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

## Endpoints and scenarios

| Purpose | URL |
|---|---|
| SOAP SDK from the demo proxy | `http://vmware-module-mock:8080/sdk` |
| Gateway from Windows | `http://127.0.0.1:18989` |
| Status and scenario control | `/_mock/status`, `/_mock/scenario` |
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
| `healthy` | Simulator inventory/performance and green vCenter appliance health. |
| `degraded` | REST appliance health becomes red, exercising the cloned template's actual vCenter health trigger. SOAP inventory remains available. |
| `unresponsive` | SDK and appliance requests receive no headers/body, exercising real collector timeouts. Control endpoints and liveness stay available for recovery. |

New samples and problem/recovery events depend on the actual polling/template intervals.
Switching scenarios changes no Zabbix items or triggers. The cloned template decides how
collection failure is reported; the mock does not inject synthetic problem events.

## Coverage limits

vcsim implements a subset of vSphere, rather than a complete appliance. Some datastore
and device-specific performance counters are unavailable and can remain unsupported in
Zabbix. Available simulator samples are synthetic, not realistic load or benchmark results.
The gateway normalizes vcsim's literal wildcard performance-series instance to the empty
aggregate instance expected by Zabbix, enabling aggregate CPU history and sparklines.
It does not manufacture missing device counters.

`degraded` tests vCenter appliance health; it does not simulate every host hardware, SAN,
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
objects; it does not overwrite changes to the existing cloned templates.

## Development checks

```powershell
python -B -m unittest discover -s tests -p 'test_mock_vmware.py'
docker compose --project-name vmware-module-mock --file dev/mock-vmware/compose.yaml logs --tail 40 proxy
```

After editing `server.py`, rebuild with `up` or Compose `up -d --build vmware-mock`.
Recreating the gateway resets its scenario to healthy. The vSphere model survives gateway
restarts and is recreated when the simulator container is recreated.
