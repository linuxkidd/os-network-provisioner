<!-- UI Volt Template -->
<div class="content-box">
    <div class="col-md-12">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th colspan="2"><h2>{{ lang._('Automated Network Provisioning') }}</h2></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><label for="vlan_id"><b>{{ lang._('VLAN ID') }}</b></label></td>
                        <td><input type="text" id="vlan_id" class="form-control" placeholder="e.g. 20"></td>
                    </tr>
                    <tr>
                        <td><label for="description"><b>{{ lang._('Description') }}</b></label></td>
                        <td><input type="text" id="description" class="form-control" placeholder="e.g. Guest Network"></td>
                    </tr>
                    <tr>
                        <td><label for="network"><b>{{ lang._('Network Address') }}</b></label></td>
                        <td><input type="text" id="network" class="form-control" placeholder="e.g. 192.168.20.0"></td>
                    </tr>
                    <tr>
                        <td><label for="mask"><b>{{ lang._('Subnet Mask (CIDR)') }}</b></label></td>
                        <td><input type="text" id="mask" class="form-control" placeholder="e.g. 24"></td>
                    </tr>
                    <tr>
                        <td><label for="parent_if"><b>{{ lang._('Parent Interface') }}</b></label></td>
                        <td><input type="text" id="parent_if" class="form-control" placeholder="e.g. vtnet0" value="vtnet0"></td>
                    </tr>
                    <tr>
                        <td><label for="firewall_group"><b>{{ lang._('Firewall Group') }}</b></label></td>
                        <td><input type="text" id="firewall_group" class="form-control" placeholder="e.g. Pier_Networks" value="Pier_Networks"></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <button id="btn_provision" class="btn btn-primary">{{ lang._('Provision Network') }}</button>
                            <div id="provision_status" style="margin-top: 15px; font-weight: bold;"></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $("#btn_provision").click(function() {
            let payload = {
                vlan_id: $("#vlan_id").val(),
                description: $("#description").val(),
                network: $("#network").val(),
                mask: $("#mask").val(),
                parent_if: $("#parent_if").val(),
                firewall_group: $("#firewall_group").val()
            };
            
            $("#provision_status").html("<span style='color:blue;'>Provisioning in progress... Please wait.</span>");
            
            $.ajax({
                type: "POST",
                url: "/api/networkprovisioner/provision/run",
                dataType: "json",
                data: JSON.stringify(payload),
                contentType: "application/json",
                success: function(data) {
                    if (data.status === "success") {
                        $("#provision_status").html("<span style='color:green;'>" + data.message + "</span><br><pre>" + data.log.join('\n') + "</pre>");
                    } else {
                        $("#provision_status").html("<span style='color:red;'>Error: " + data.message + "</span>");
                    }
                },
                error: function() {
                    $("#provision_status").html("<span style='color:red;'>API Request Failed. Check console or core logs.</span>");
                }
            });
        });
    });
</script>
