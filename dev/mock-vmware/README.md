# Disposable VMware monitoring demo

This fixture provides a real vSphere SOAP SDK endpoint using Broadcom's
[govmomi vcsim](https://github.com/vmware/govmomi/blob/v0.55.2/vcsim/README.md), plus the
REST appliance health endpoints used by the VMware template. The simulator image is pinned
to vcsim 0.55.2 by digest. Zabbix discovers and polls the simulation normally; the frontend
has no demo-specific branches or dependency on these development tools.

The Compose project also starts a disposable **Zabbix 7.0.22 SQLite proxy** with two VMware
collectors. Only the demo vCenter and its discovered hosts use it. Setup does not edit the
existing Zabbix server configuration, restart that server, or alter production templates.

## Requirements

- Python 3.10+ and Docker Compose; Python uses only the standard library.
- An existing Zabbix 7.0 server reachable as `zabbix-server` on the external Docker network
  `zabbix-network`, as in this development environment.
- The installed `VMware`, `VMware Hypervisor`, and `VMware Guest` templates.
- Zabbix API rights to export/import templates and manage demo hosts, groups, and its proxy.

## Start

From the VMware module directory:

```powershell
python dev/mock-vmware/demo.py up
```

Enter the Zabbix password when prompted. `ZABBIX_PASSWORD` or `ZABBIX_API_TOKEN` can be used
instead. The URL defaults to `http://127.0.0.1`; override it using `--zabbix-url`.
Credentials are never saved in the local fixture state file.

Setup clones the **installed** three VMware templates as `DEMO - VMware`,
`DEMO - VMware Hypervisor`, and `DEMO - VMware Guest`, with separate UUIDs. The cloned
checks/discovery rules run every 15 seconds. Cloned VM prototypes discover all simulator VMs;
the production template's tag filters and intervals remain unchanged.

Created objects are marked with `fixture=vmware-module-mock-v1`. The fixture contains:

- vCenter host `vmware-module-demo`, displayed as **DEMO - VMware vCenter**;
- proxy `vmware-module-demo-proxy`;
- group `VMware module demo` and template group `Templates/VMware module demo`;
- one datacenter and cluster, two hypervisors, four VMs, and two shared datastores;
- distributed switch `DVS0`, with four VM NIC ports, two modeled physical uplinks,
  and two unused ports when detailed switch simulation is enabled;
- location macro `{$VMWARE.LOCATION}=Demo, VMware simulator`;
- inventory notes `Synthetic VMware vCenter infrastructure` on the vCenter.

Discovered technical host names start with `vmware-module-demo-`. Their displayed names
retain the simulator's actual `DC0_...` names so placement comparisons continue to work.
They belong to the isolated demo group. Real LLD parent relationships make the hosts visible
to the module without injecting records into the Zabbix database.

Allow **2–5 minutes** for proxy configuration, discovery and performance samples. CPU
sparklines grow as history accumulates. The command prints the vCenter detail link.

```powershell
python dev/mock-vmware/demo.py status
```

## Folder-based discovery groups

Manage folder-to-host-group rules in **Data collection > Templates > DEMO - VMware > Macros**.
Edit the template macro **`{$VMWARE.VM.FOLDER.GROUPS}`**. Its default value is:

```json
{"FB-STP":"STP","FB-MSP":"MSP","FB-OPS":"RUN"}
```

The JSON keys are main vCenter folder names; values are Zabbix host group
names. JSON supports punctuation and spaces in names. Save the macro and allow the normal
proxy configuration and discovery cycles to apply it. No script rerun or container rebuild
is needed. Setup creates the default macro only when it is missing and preserves edits.

The **DEMO - VMware** VM discovery preprocessing reads the macro at runtime, matches the
native `{#VM.FOLDER}` path and exposes the destination as `{#VM.FOLDER.GROUP}`. The VM host
prototype uses this single folder group prototype. Every VM also belongs to the fixture's
base group `VMware module demo`.

| VM | Native vCenter folder | Discovered host group |
|---|---|---|
| `DC0_C0_RP0_VM0`, `DC0_C0_RP0_VM1` | `FB-STP` | `STP` |
| `DC0_C0_RP0_VM2`, `DC0_C0_RP0_VM3` | `FB-MSP` | `MSP` |
| Any VM moved to `FB-OPS` | `FB-OPS` | `RUN` |

Only the first folder in the native path is matched; all subfolders are ignored.
For example, `FB-MSP/05_app` and `FB-MSP/05_app/Restricted` both use the `FB-MSP` rule,
even if a full-path or child-folder mapping exists. Matching is exact and case sensitive:
`FB-STP-old` does not match `FB-STP`. The default `vm` root folder always uses the fallback,
even if `vm` is listed in the mapping. Empty and unmatched folders also use the template macro
**`{$VMWARE.VM.FOLDER.GROUP.DEFAULT}`**, initially `VMware module demo/Unmapped folders`.
Set it to your preferred group name in the same **Macros** tab. Discovery reads both
macros at runtime; setup preserves edits to either macro. The default must be a nonempty
valid group name and follows the same group requirements as mapped destinations.
Moving a VM updates its discovered group on subsequent collector/discovery cycles.

Invalid JSON, non-object mappings and invalid/empty destination names produce a discovery
preprocessing error. Macro values are URL-encoded before entering the JavaScript string,
so apostrophes, quotes and Unicode remain data. This uses the fixture's Zabbix 7.0.22 runtime.
Mapped names must be new groups or existing discovered groups: Zabbix 7.0 group prototypes
[cannot reuse manually created groups](https://www.zabbix.com/documentation/7.0/en/manual/discovery/low_level_discovery/host_prototypes).

Setup creates native folders `FB-STP`, `FB-MSP` and `FB-OPS`. It moves only VMs still in the
datacenter's default VM folder: the first two go to `FB-STP`, the last two to `FB-MSP`.
Existing user folder moves are preserved. Repeating `up` upgrades owned demo configuration
in place, preserving discovered VM identities and history, other prototype tags, custom
group prototypes and preprocessing. `status` prints native folders and Zabbix groups.

The authenticated `/_mock/inventory` endpoint reads the native inventory (`GET`) or seeds
folders (`POST` with `{"seed_folders":true}`). Recreating the simulator resets its native
folders; run `up` to seed the new inventory. Discovery grouping uses folders only.

## Endpoints and scenarios

| Purpose | URL |
|---|---|
| SOAP SDK from the demo proxy | `http://vmware-module-mock:8080/sdk` |
| Gateway from Windows | `http://127.0.0.1:18989` |
| Status and scenario control | `/_mock/status`, `/_mock/scenario` |
| Native VM folder inventory / seed folders | `/_mock/inventory` (`GET` / `POST`) |
| Container liveness | `/healthz` |

SOAP/REST and scenario control credentials are **mock / mock**. SOAP login uses the SDK
session; the REST API supports both API session-header and session-cookie authentication.

```powershell
python dev/mock-vmware/demo.py scenario --scenario degraded
python dev/mock-vmware/demo.py scenario --scenario unresponsive
python dev/mock-vmware/demo.py scenario --scenario healthy
```

| Scenario | Behavior |
|---|---|
| `healthy` | Simulator inventory/performance, green appliance health, VM NICs and uplinks up; unused ports have no attachment. |
| `degraded` | Appliance health becomes red. The first VM NIC port is blocked, link-down and conflicting, with growing drop/exception counters. SOAP inventory remains available. |
| `unresponsive` | SDK and appliance requests receive no headers/body, exercising real collector timeouts. Control endpoints and liveness stay available for recovery. |

New samples and problem/recovery events depend on the actual polling/template intervals.
Switching scenarios changes no Zabbix items or triggers. The cloned template decides how
collection failure is reported; the mock does not inject synthetic problem events.

## Coverage limits

To exercise optional distributed-switch monitoring, import
[`templates/vmware-dvswitches.yaml`](../../templates/vmware-dvswitches.yaml) and link
**VMware Distributed Switches** to **DEMO - VMware vCenter** alongside its demo template.
For faster local collection, set `{$VMWARE.DVS.INTERVAL}=15s` and
`{$VMWARE.DVS.DISCOVERY.INTERVAL}=1m` on that host. Repeating `up` accepts this companion;
`down` removes its host link with the demo but preserves the imported companion template.

The simulator includes `DVS0`. The gateway translates its base
`DistributedVirtualSwitch` SOAP type to the `VmwareDistributedVirtualSwitch` subtype
Zabbix discovers. A read-only, batched SDK inventory query supplies the **actual simulator**
VM IDs, NIC keys, MAC addresses, host placement, switch UUID and port-group membership.
Its result is cached for 30 seconds per SDK session; the temporary ContainerView is destroyed
after each inventory refresh. No credentials or monitoring records are written by this helper.

The pinned vcsim leaves DVPort keys, attachments and runtime fields incomplete. The gateway
therefore models complete `FetchDVPorts` responses for this disposable fixture:

| Field | Demo behavior |
|---|---|
| VM attachments | One port per actual distributed-backed VM NIC, with the real VM ID, NIC key, MAC and host reference. |
| Port keys | Actual keys when present; otherwise stable `vm-ID-nic-KEY` keys derived from the NIC identity. |
| Uplinks | One modeled `vmnic0` per participating host, in the simulator's actual uplink group. The pinned simulator omits uplink flags, so its `DVS*-DVUplinks-*` group name identifies that group. |
| Unused ports | One modeled, unattached port per reported port group. |
| VLANs | Synthetic VLAN 100 for VM ports; VLAN 0–4094 trunking for uplinks. |
| Runtime | Link, block/conflict, peer, DirectPath, host-local, cookie, timestamp and explicit synthetic-status details. |
| Counters | Sixteen cumulative byte/packet/drop/exception counters per port. Attached-port traffic grows over time; the degraded VM port accumulates drops and exceptions. |
| Port configuration | Names, descriptions and config versions are available in full SDK responses. Zabbix `state` mode excludes configuration nodes as usual. |

The module's Ports and Connected resources tabs can now display all four VM names through
the companion template's real batched VM-name lookup. The default dataset has **eight ports,
six attachments and six links up**. Degraded has one blocked/conflicting VM port and five
links up. Returning to healthy restores its link and clears the flags; cumulative error
counters retain their history. Counters reset when the gateway container is recreated.

Native port criteria (attachment, active/link state, uplink, NSX, host, port key and inside/
outside port-group selection) are applied after modeling. SOAP faults are preserved. If
inventory enrichment fails, the gateway returns native vcsim data rather than manufacturing
healthy results; `/_mock/status` and `demo.py status` report an enrichment error.

The added runtime states, VLANs, port assignments where absent, uplinks and counters are
**synthetic test telemetry**, not measured network traffic or a complete vSphere implementation.
`statusDetail` labels synthetic port states. The behavior exists only in the demo gateway;
the frontend module has no special fixture path.

To compare against untouched native DVPort payloads, disable enrichment and recreate only
the gateway (set the variable to `true` and repeat to re-enable it):

```powershell
$env:VMWARE_MOCK_DVS_DETAILS = 'false'
docker compose --project-name vmware-module-mock --file dev/mock-vmware/compose.yaml up -d vmware-mock
```

The updated companion also collects a shared VM ID/name inventory through the native
VMware collector, resolving all referenced VM IDs in one Zabbix API read. The simulator
provides VM IDs and names independently of port enrichment. When enrichment is disabled,
native ports can omit `connectee` and no VM-to-port assignment can be inferred from names
alone. Regression checks cover populated attachments, selection criteria, batch resolution,
name search, scenario recovery and ID fallback.

vcsim implements a subset of vSphere, rather than a complete appliance. Some datastore
and device-specific performance counters are unavailable and can remain unsupported in
Zabbix. Available simulator samples are synthetic, not realistic load or benchmark results.
The gateway normalizes vcsim's literal wildcard performance-series instance to the empty
aggregate instance expected by Zabbix, enabling aggregate CPU history and sparklines.
This normalization does not fill missing datastore or hardware-device counters. The synthetic
distributed-port counters described above are a separate, explicit fixture feature.

`degraded` tests vCenter appliance health and the selected distributed-port failure; it does not simulate every host hardware, SAN,
VMware alarm, HA or storage failure. This fixture does not run real VMs or expose a vCenter UI.
Discovery and simple-check collection use the same SDK protocol as real VMware monitoring,
as described in the [Zabbix 7.0 documentation](https://www.zabbix.com/documentation/7.0/en/manual/vm_monitoring).

## Remove

```powershell
python dev/mock-vmware/demo.py down
```

This removes the owned vCenter, cloned templates/host prototypes and discovered hosts,
proxy, empty groups created by setup, and the three demo containers. Stored monitoring
history is disposable. Other hosts and the original templates are preserved. Removal
refuses foreign template consumers, unexpected proxy consumers, unmarked objects, or
a demo host whose URL was changed to another VMware endpoint.

Run `up` again to recreate the dataset. Repeating `up` while it exists reuses the owned
objects and applies the folder discovery migration described above. Other changes to the
existing cloned templates are retained.

## Development checks

Distributed-switch checks (Python requires PyYAML; JavaScript tests require Node.js,
or `NODE_BINARY` pointing to its executable):

```powershell
python -B -m unittest discover -s dev/tests -p 'test_dvs*.py'
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-wmvare-monitoring/dev/tests/dvswitches.php /usr/share/zabbix
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-wmvare-monitoring/dev/tests/dvswitch-detail.php /usr/share/zabbix
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-wmvare-monitoring/dev/tests/dvswitch-vm-names.php /usr/share/zabbix
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-wmvare-monitoring/dev/tests/dvswitch-host-names.php /usr/share/zabbix
```

Native menu/dialog regression checks use Zabbix's actual menu implementation:

```powershell
node dev/tests/dvswitch-menu.mjs ../../zabbix-src-7.0/ui/js/menupopup.js
node dev/tests/dvswitch-port-details.mjs
```

Adjust the second argument to the location of your Zabbix 7.0 frontend source.

```powershell
python -B -m unittest discover -s tests -p 'test_mock_vmware.py'
python -B -m unittest discover -s dev/tests -p 'test_vmware_folders.py'
docker compose --project-name vmware-module-mock --file dev/mock-vmware/compose.yaml logs --tail 40 proxy
```

After editing `server.py`, rebuild with `up` or Compose `up -d --build vmware-mock`.
Recreating the gateway resets its scenario to healthy. The vSphere model survives gateway
restarts and is recreated when the simulator container is recreated.
