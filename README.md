# os-network-provisioner
OPNsense plugin to simplify network provisioning.

## Assumptions:
1. New VLANs will be created with the template `$parent_vlan$ID`
2. KeaDHCP is used for DHCPv4
3. You're using Firewall Groups

## How to Compile and Install on OPNsense
To install this custom plugin, you will need root SSH access to your OPNsense box to utilize the FreeBSD tools framework.


1. Clone the `os-network-provisioner` repository:
```
pkg install git
git clone https://github.com/linuxkidd/os-network-provisioner.git
```

2. Clone the OPNsense Tools/Ports tree (if not already present):

```
git clone https://github.com/opnsense/tools /usr/tools
git clone https://github.com/opnsense/plugins /usr/plugins
```

3. Move the plugin to the build tree:

```
mv /root/os-network-provisioner /usr/plugins/net/network-provisioner
```

4. Build the package:
```
cd /usr/plugins/net/network-provisioner
make package
```

5. Install the compiled package:

```
pkg install work/pkg/os-network-provisioner-1.0.pkg
```

6. Restart the Web GUI:

```
configctl webgui restart
```

Once the web GUI restarts, log in as an administrator. You will see a new "Network Provisioner" menu item under Interfaces, allowing you to rapidly deploy a new network.
