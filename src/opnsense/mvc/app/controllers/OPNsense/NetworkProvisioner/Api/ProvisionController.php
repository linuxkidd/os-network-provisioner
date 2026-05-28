<?php
namespace OPNsense\NetworkProvisioner\Api;

use OPNsense\Base\ApiControllerBase;

/**
 * Class ProvisionController
 * @package OPNsense\NetworkProvisioner\Api
 */
class ProvisionController extends ApiControllerBase
{
    /**
     * Internal helper to do IP math
     */
    private function getNetworkDetails($network, $cidr) {
        $ip_long = ip2long($network);
        $network_size = pow(2, (32 - $cidr));
        return [
            'interface_ip' => long2ip($ip_long + 1),
            'dhcp_start'   => long2ip($ip_long + 2),
            'dhcp_end'     => long2ip($ip_long + $network_size - 2)
        ];
    }

    private function genUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function runAction()
    {
        if (!$this->request->isPost()) {
            return array("status" => "failed", "message" => "POST required");
        }

        $data = $this->request->getJsonRawBody(true);
        $vlan_id     = $data['vlan_id'] ?? '';
        $description = $data['description'] ?? '';
        $network     = $data['network'] ?? '';
        $mask        = $data['mask'] ?? '';
        $parent_if   = $data['parent_if'] ?? 'vtnet0';
        $firewall_group = $data['firewall_group'] ?? '';

        if (empty($vlan_id) || empty($network) || empty($mask) || empty($description)) {
            return array("status" => "failed", "message" => "Missing required fields.");
        }
        $descr_nospace = str_replace(' ','',$description);

        $log = [];

        require_once("config.inc");
        require_once("interfaces.inc");
        require_once("util.inc");
        require_once("auth.inc");

        global $config;
        $phalcon_app_config = $config;
        $config = parse_config();


        // ====================================================================
        // PRE-FLIGHT VALIDATION CHECKS
        // ====================================================================

        // Helper to get network boundaries for overlap checking
        $get_net_bounds = function($ip, $cidr) {
            $mask = ~((1 << (32 - $cidr)) - 1);
            $network = ip2long($ip) & $mask;
            $broadcast = $network | ~$mask;
            return ['start' => $network, 'end' => $broadcast];
        };

        $new_net = $get_net_bounds($network, $mask);

        // 1. Check Interface Network Overlaps & Duplicate Descriptions
        foreach ($config['interfaces'] as $if_id => $if_data) {
            // Check descriptions
            if (isset($if_data['descr']) && strtolower($if_data['descr']) === strtolower($descr_nospace)) {
                return ["status" => "failed", "message" => "An interface with the description '$descr_nospace' already exists."];
            }

            // Check IP Overlaps (only for static IPv4)
            if (isset($if_data['ipaddr']) && isset($if_data['subnet']) && filter_var($if_data['ipaddr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $existing_net = $get_net_bounds($if_data['ipaddr'], $if_data['subnet']);

                // Overlap math: Max(StartA, StartB) <= Min(EndA, EndB)
                if (max($new_net['start'], $existing_net['start']) <= min($new_net['end'], $existing_net['end'])) {
                    return ["status" => "failed", "message" => "Network Overlap: $network/$mask conflicts with existing interface '{$if_data['descr']}' ({$if_data['ipaddr']}/{$if_data['subnet']})."];
                }
            }
        }

        // 2. Check Kea DHCP Subnet Overlaps
        foreach ($config['OPNsense']['Kea']['dhcp4']['subnets']['subnet4'] as $kea_subnet) {
            // Kea stores as "192.168.1.0/24"
            $parts = explode('/', $kea_subnet['subnet']);
            if (count($parts) === 2) {
                $existing_kea = $get_net_bounds($parts[0], $parts[1]);
                if (max($new_net['start'], $existing_kea['start']) <= min($new_net['end'], $existing_kea['end'])) {
                    return ["status" => "failed", "message" => "DHCP Overlap: Network conflicts with existing Kea subnet '{$kea_subnet['subnet']}'."];
                }
            }
        }

        // 3. Strict VLAN Collision Checking
        foreach ($config['vlans']['vlan'] as $v) {
            if ($v['tag'] == $vlan_id) {
                if ($v['if'] !== $parent_if) {
                    return ["status" => "failed", "message" => "VLAN Collision: Tag $vlan_id is already assigned to a different physical interface ({$v['if']})."];
                } else {
                    // If it's on the same parent, we will allow it to attach, but log a warning
                    $log[] = "Notice: Reusing existing VLAN $vlan_id on $parent_if.";
                }
            }
        }

        // ====================================================================
        // END VALIDATION - PROCEED WITH CONFIGURATION
        // ====================================================================

        $vlan_iface_name = "{$data['parent_if']}_vlan{$vlan_id}";

        try {
            // 1. Create VLAN
            if (!isset($config['vlans'])) { $config['vlans'] = ['vlan' => []]; }

            $vlan_uuid = $this->genUUID();
            $config['vlans']['vlan'][] = [
                '@attributes' => [ 'uuid' => $vlan_uuid ],
                'if' => $parent_if,
                'tag' => $vlan_id,
                'pcp' => 0,
                'descr' => $description,
                'vlanif' => $vlan_iface_name
            ];
            $log[] = "Added VLAN $vlan_id to hardware.";

            // 2. Assign Logical Interface
            $opt_prefix = "opt";
            $next_id = 1;
            while (isset($config['interfaces'][$opt_prefix . $next_id])) {
                $next_id++;
            }
            $logical_if_name = $opt_prefix . $next_id;

            $net_details = $this->getNetworkDetails($network, $mask);

            $config['interfaces'][$logical_if_name] = [
                'if' => $vlan_iface_name,
                'descr' => $descr_nospace,
                'enable' => '1',
                'ipaddr' => $net_details['interface_ip'],
                'subnet' => $mask,
                'type' => 'static'
            ];
            $log[] = "Assigned logical interface $logical_if_name ({$net_details['interface_ip']}/{$mask}).";

            // 3. Add to Firewall Group
            if (!empty($firewall_group)) {
                if (isset($config['ifgroups']['ifgroupentry'])) {
                    foreach ($config['ifgroups']['ifgroupentry'] as &$group) {
                        if ($group['ifname'] === $firewall_group) {
                            $members = explode(' ', $group['members']);
                            if (!in_array($logical_if_name, $members)) {
                                $members[] = $logical_if_name;
                                $group['members'] = implode(' ', $members);
                                $log[] = "Appended interface to $firewall_group.";
                            }
                            break;
                        }
                    }
                }
            }

            // 4. Configure Kea DHCP (MVC Model Interaction via API or raw Kea config)
            // Note: For Kea MVC, we usually use the model API. Doing it raw here.
            if (!isset($config['OPNsense']['Kea']['dhcp4']['subnets']['subnet4'])) {
                $config['OPNsense']['Kea']['dhcp4']['subnets']['subnet4'] = [];
            }

            // Generate a random UUID for the new Kea Subnet entry
            $uuid = $this->genUUID();
            $config['OPNsense']['Kea']['dhcp4']['subnets']['subnet4'][] = [
                '@attributes' => [ 'uuid' => $uuid ],
                'subnet' => "$network/$mask",
                'pools' => "{$net_details['dhcp_start']}-{$net_details['dhcp_end']}",
                'description' => $description
            ];
            $log[] = "Added Kea DHCP IPv4 subnet.";

            // 5. Write to disk and apply
            if (!isset($_SESSION['Username'])) {
                $_SESSION['Username'] = $this->getUserName() ?: 'API_NetworkProvisioner';
            }

            write_config("Automated Provisioning: Deployed $description on VLAN $vlan_id");
            $log[] = "Configuration saved to config.xml.";

            $config = $phalcon_app_config;

            // Reload systems
            interfaces_vlan_configure();
            interfaces_setup();

            // Reload Kea using native configd backend
            $backend = new \OPNsense\Core\Backend();
            $backend->configdRun("kea restart");

            $log[] = "Services reloaded successfully.";

            return ["status" => "success", "message" => "Provisioning Complete.", "log" => $log];

        } catch (\Exception $e) {
            return ["status" => "failed", "message" => $e->getMessage()];
        }
    }
}
