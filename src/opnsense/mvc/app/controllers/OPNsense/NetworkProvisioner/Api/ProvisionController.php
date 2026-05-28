<?php
namespace OPNsense\NetworkProvisioner\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Config;
use OPNsense\Core\Backend;

class ProvisionController extends ApiControllerBase
{

    private function genUUID() {
        $uuid_bytes = random_bytes(16);
        $uuid_bytes[6] = chr(ord($uuid_bytes[6]) & 0x0f | 0x40);
        $uuid_bytes[8] = chr(ord($uuid_bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuid_bytes), 4));
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

        try {
            // 2. Fetch Native OPNsense Config as SimpleXMLElement
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
                $uuid = $this->genUUID();
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

            $uuid = $this->genUUID();
            $new_kea = $configXML->OPNsense->Kea->dhcp4->subnets->addChild('subnet4');
            $new_kea->addAttribute('uuid', $uuid);
            $new_kea->addChild('subnet', "$network/$mask");
            $new_kea->addChild('pools', "$dhcp_start - $dhcp_end");
            $new_kea->addChild('description', "$description Pool");
            $log[] = "Generated Kea DHCP pool.";

            // 3. Save Configuration Natively
            // This safely locks the file, writes the new XML, and unlocks it.
            $configObj->save(); 
            $log[] = "Native configuration saved successfully.";

            // 4. Trigger Backend Daemons via configd
            $backend = new Backend();
            
            // Call our custom isolated network reload script
            $network_reload = trim($backend->configdRun("networkprovisioner reload"));
            if ($network_reload === "OK") {
                $log[] = "Interfaces reloaded securely in backend.";
            } else {
                throw new \Exception("Backend network reload failed: " . $network_reload);
            }

            // Call the native Kea restart daemon
            $backend->configdRun("kea restart");
            $log[] = "Kea DHCP daemon restarted.";

            return ["status" => "success", "log" => $log];

        } catch (\Exception $e) {
            return ["status" => "failed", "message" => $e->getMessage()];
        }
    }
}
