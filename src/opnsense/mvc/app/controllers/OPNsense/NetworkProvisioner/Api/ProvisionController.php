<?php
namespace OPNsense\NetworkProvisioner\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Config;
use OPNsense\Core\Backend;

class ProvisionController extends ApiControllerBase
{

    private function gen_UUID() {
        $uuid_bytes = random_bytes(16);
        $uuid_bytes[6] = chr(ord($uuid_bytes[6]) & 0x0f | 0x40);
        $uuid_bytes[8] = chr(ord($uuid_bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuid_bytes), 4));
    }

    private function get_net_bounds($ip, $cidr) {
                $mask_int = ~((1 << (32 - $cidr)) - 1);
                $network_int = ip2long($ip) & $mask_int;
                $broadcast_int = $network_int | ~$mask_int;
                return ['start' => $network_int, 'end' => $broadcast_int];
    }

    public function runAction()
    {
        if (!$this->request->isPost()) {
            return array("status" => "failed", "message" => "POST required");
        }

        $data = $this->request->getJsonRawBody(true);
        $vlan_id     = preg_replace('/[^0-9]/', '', $data['vlan_id'] ?? '');
        $description = preg_replace('/[^a-zA-Z0-9 _-]/', '', $data['description'] ?? '');
        $network     = $data['network'] ?? '';
        $mask        = preg_replace('/[^0-9]/', '', $data['mask'] ?? '');
        $parent_if   = preg_replace('/[^a-zA-Z0-9]/', '', $data['parent_if'] ?? 'vtnet0');
        $fw_group    = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['fw_group'] ?? '');

        if (empty($vlan_id) || empty($network) || empty($mask) || empty($description)) {
            return array("status" => "failed", "message" => "Missing required fields.");
        }

        // 1. IP Math
        $ip_long = ip2long($network);
        $network_size = pow(2, (32 - $mask));
        $interface_ip = long2ip($ip_long + 1);
        $dhcp_start   = long2ip($ip_long + 2);
        $dhcp_end     = long2ip($ip_long + $network_size - 2);

        $log = [];

        // Check interfaces for overlap
        $new_net = $this->get_net_bounds($network, $mask);
        if (isset($configXML->interfaces)) {
            foreach ($configXML->interfaces->children() as $if_id => $if_data) {

                // Duplicate Description Check
                if (isset($if_data->descr) && strtolower((string)$if_data->descr) === strtolower($description)) {
                    throw new \Exception("An interface with the description '$description' already exists.");
                }

                // Network Overlap Check (Only process static IPv4 interfaces)
                if (isset($if_data->ipaddr) && isset($if_data->subnet) && filter_var((string)$if_data->ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $existing_net = $get_net_bounds((string)$if_data->ipaddr, (int)$if_data->subnet);

                    // Mathematical Overlap condition: Max(StartA, StartB) <= Min(EndA, EndB)
                    if (max($new_net['start'], $existing_net['start']) <= min($new_net['end'], $existing_net['end'])) {
                        $conflict_descr = isset($if_data->descr) ? (string)$if_data->descr : $if_id;
                        throw new \Exception("Network Overlap: $network/$mask conflicts with existing interface '{$conflict_descr}' (" . (string)$if_data->ipaddr . "/" . (string)$if_data->subnet . ").");
                    }
                }
            }
        }

        // Check for Kea DHCP Range overlaps
        if (isset($configXML->OPNsense->Kea->dhcp4->subnets->subnet4)) {
            foreach ($configXML->OPNsense->Kea->dhcp4->subnets->subnet4 as $kea_subnet) {
                $subnet_str = (string)$kea_subnet->subnet; // Format: "192.168.1.0/24"
                $parts = explode('/', $subnet_str);

                if (count($parts) === 2 && filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $existing_kea = $get_net_bounds($parts[0], (int)$parts[1]);

                    if (max($new_net['start'], $existing_kea['start']) <= min($new_net['end'], $existing_kea['end'])) {
                        throw new \Exception("DHCP Overlap: $network/$mask conflicts with existing Kea subnet '{$subnet_str}'.");
                    }
                }
            }
        }

        // Check for overlapping VLANs
        if (isset($configXML->vlans->vlan)) {
            foreach ($configXML->vlans->vlan as $v) {
                if ((string)$v->tag === $vlan_id && (string)$v->if !== $parent_if) {
                    throw new \Exception("VLAN Collision: Tag $vlan_id is already assigned to a different physical interface (" . (string)$v->if . ").");
                }
            }
        }

        try {
            // Fetch Native OPNsense Config as SimpleXMLElement
            $configObj = Config::getInstance();
            $configXML = $configObj->object();

            $vlan_iface_name = "{$parent_if}_vlan{$vlan_id}";

            // A. Write VLAN Node
            if (!isset($configXML->vlans)) { $configXML->addChild('vlans'); }
            $vlan_exists = false;
            foreach ($configXML->vlans->vlan as $v) {
                if ((string)$v->tag === $vlan_id && (string)$v->if === $parent_if) {
                    $vlan_exists = true; break;
                }
            }
            if (!$vlan_exists) {
                $uuid = $this->gen_UUID();
                $new_vlan = $configXML->vlans->addChild('vlan');
                $new_vlan->addAttribute('uuid', $uuid);
                $new_vlan->addChild('if', $parent_if);
                $new_vlan->addChild('tag', $vlan_id);
                $new_vlan->addChild('pcp', '0');
                $new_vlan->addChild('descr', $description);
                $new_vlan->addChild('vlanif', $vlan_iface_name);
                $log[] = "Appended VLAN $vlan_id to config.";
            }

            // B. Write Logical Interface Node
            $next_id = 1;
            while (isset($configXML->interfaces->{'opt' . $next_id})) { $next_id++; }
            $logical_if_name = 'opt' . $next_id;

            $new_if = $configXML->interfaces->addChild($logical_if_name);
            $new_if->addChild('if', $vlan_iface_name);
            $new_if->addChild('descr', str_replace(' ','',$description));
            $new_if->addChild('enable', '1');
            $new_if->addChild('ipaddr', $interface_ip);
            $new_if->addChild('subnet', $mask);
            $new_if->addChild('type', 'static');
            $log[] = "Mapped logical interface $logical_if_name.";

            // C. Write Firewall Group Membership
            if (isset($configXML->ifgroups->ifgroupentry)) {
                foreach ($configXML->ifgroups->ifgroupentry as $group) {
                    if ((string)$group->ifname === $fw_group) {
                        $current_members = (string)$group->members;
                        if (strpos($current_members, $logical_if_name) === false) {
                            $group->members = trim($current_members . "," . $logical_if_name);
                            $log[] = "Appended interface to Firewall Group '$fw_group'.";
                        }
                        break;
                    }
                }
            }

            // D. Write Kea DHCP Subnet Node (MVC uses UUID attributes)
            if (!isset($configXML->OPNsense->Kea->dhcp4->subnets)) {
                // Ensure parent paths exist natively
                if (!isset($configXML->OPNsense)) $configXML->addChild('OPNsense');
                if (!isset($configXML->OPNsense->Kea)) $configXML->OPNsense->addChild('Kea');
                if (!isset($configXML->OPNsense->Kea->dhcp4)) $configXML->OPNsense->Kea->addChild('dhcp4');
                $configXML->OPNsense->Kea->dhcp4->addChild('subnets');
            }

            $kea_ifaces = $configXML->OPNsense->Kea->dhcp4->general->interfaces;
            if(strpos($kea_ifaces, $logical_if_name) === false) {
                $configXML->OPNsense->Kea->dhcp4->general->interfaces = trim($kea_ifaces . "," . $logical_if_name);
            }

            $uuid = $this->gen_UUID();
            $new_kea = $configXML->OPNsense->Kea->dhcp4->subnets->addChild('subnet4');
            $new_kea->addAttribute('uuid', $uuid);
            $new_kea->addChild('subnet', "$network/$mask");
            $new_kea->addChild('pools', "{$dhcp_start}-{$dhcp_end}");
            $new_kea->addChild('description', $description);
            $log[] = "Generated Kea DHCP pool.";

            // 3. Save Configuration Natively
            // This safely locks the file, writes the new XML, and unlocks it.
            $configObj->save();
            $log[] = "Native configuration saved successfully.";

            // 4. Trigger Backend Daemons via configd
            $backend = new Backend();

            // Sync VLAN hardware 
            $backend->configdRun("interface vlan configure");
            $log[] = "VLAN hardware synced seamlessly.";

            // Bring up the newly created interface
            $backend->configdRun("interface reconfigure {$logical_if_name}");
            $log[] = "Logical interface {$logical_if_name} brought up securely.";

            // Reload Packet Filter (pf)
            $backend->configdRun("filter reload");
            $log[] = "Firewall rules parsed new group membership.";

            // Restart Kea DHCP
            $backend->configdRun("kea restart");
            $log[] = "Kea DHCP daemon restarted.";

            return ["status" => "success", "log" => $log];

        } catch (\Exception $e) {
            return ["status" => "failed", "message" => $e->getMessage()];
        }
    }
}
