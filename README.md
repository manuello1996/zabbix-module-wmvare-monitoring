# VMware Monitoring — Zabbix frontend module

A read-only Zabbix 7.0 frontend module that turns the metrics and hosts created by the official
`VMware` template into a navigable VMware environment overview.

The module does not connect to vCenter and does not need VMware credentials. It reads data through
the Zabbix API with the permissions of the logged-in user.

## Features

- Multiple-vCenter overview under **Monitoring → VMware**
- vCenter health, product/version, active problems and discovered-object totals
- Reliable vCenter-to-object correlation using the Zabbix low-level discovery rule that created each
  hypervisor and VM host
- Cluster summary and detail page with native tags, stable cluster
  properties and cluster-service counters, hypervisor/VM inventory, CPU and memory capacity, storage, sensor,
  network, power and problem rollups, plus an estimated N+1 headroom calculation
- Discovered hypervisor table:
  - datacenter and cluster placement
  - connection and health states
  - CPU history sparkline and memory utilization
  - VM count, uptime, version and active problems
  - a Sensors action showing discovered hardware sensors, latest mapped VMware state, collection
    errors and related active problems
- Discovered virtual machine table:
  - hypervisor, datacenter and cluster placement
  - power and runtime state
  - CPU history, memory, committed storage, VMware Tools, snapshots and uptime
  - active problems
- Datastores per hypervisor:
  - type, capacity and free percentage
  - read/write latency and IOPS
  - multipath count
- Separate unique-datastore and per-hypervisor attachment views using datastore UUID identity
- Server-side pagination and filtering for virtual machines and datastore views
- Lazy-loaded tabs so detailed metrics are requested only when opened
- VMware-specific tabs for clusters and alarms
- A combined datastore tab with unique datastores and their per-hypervisor attachments
- Batched CPU history requests to keep large environments within request-size limits
- Theme-aware styling for the Zabbix blue and dark themes
- Active-problem aggregation across the vCenter and its discovered hypervisors and VMs

## Requirements

| Component | Version |
|---|---|
| Zabbix server and frontend | 7.0 |
| PHP | Version supported by the Zabbix 7.0 frontend |
| Template | Official `VMware` template represented by `vmware.yml` in this repository |

Hypervisors and VMs must be created by the host prototypes in the official template. Manually
created hosts linked directly to `VMware Hypervisor` or `VMware Guest` are not assigned to a
vCenter because they do not have an originating discovery rule.

## Installation

1. Place this directory in the Zabbix frontend `modules` directory. The recommended directory name
   is `vmware_monitoring`.
2. Make the files readable by the web server.
3. In Zabbix, open **Administration → General → Modules** and select **Scan directory**.
4. Enable **VMware Monitoring**.
5. Open **Monitoring → VMware**.

Import the included `vmware.yml` when upgrading an existing installation. Its cluster discovery now
creates the cluster-native property, tag and performance-counter items used by the detail
page. Values remain unavailable until the next cluster discovery and first item collection.

The overview detects vCenter hosts through the monitored
`vmware.version[{$VMWARE.URL}]` item supplied by the official `VMware` template.

## Optional DVSwitch template

Import `vmware_dvswitch.yml` to add vSphere Distributed Switch and DVPort monitoring. The import
contains two cooperating templates:

- **VMware DVSwitch discovery** is linked to the vCenter host and creates one host per discovered
  DVSwitch.
- **VMware DVSwitch** is linked automatically to those hosts and derives port state, traffic,
  drops, exceptions and metadata from one `vmware.dvswitch.fetchports.get` master item per switch.

The default port filter is `active:true`. Adjust `{$VMWARE.DVSWITCH.PORT.FILTER}` using the
`DistributedVirtualSwitchPortCriteria` fields when uplink, port group, host, connected-state or NSX
filtering is required.

## Test notes

The current development environment does not contain a running Zabbix frontend or PHP CLI. Static
structure and JSON/JavaScript checks can be performed locally, but the first installation should
specifically verify:

- the shape of `host.get` → `selectDiscoveryRule` on the installed Zabbix 7.0 patch level;
- discovered item tags returned by `item.get` → `selectTags`;
- vCenter, hypervisor and VM value-map rendering against collected numeric values;
- behavior with users who have permission to only part of a vCenter's discovered environment.

If a runtime error occurs, include the Zabbix frontend error text and the related PHP log entry.
No VMware credentials or secret macro values are needed for troubleshooting.
