# VMware Monitoring for Zabbix 7.0

A read-only Zabbix frontend module that turns the data collected by Zabbix's official VMware
template into a navigable overview of vCenters, hypervisors, virtual machines, clusters,
datastores, vCenter issues, active problems, and hardware sensors.

The module does not connect to VMware directly. It reads existing monitoring data through the
Zabbix API and applies the permissions of the signed-in Zabbix user.

## Features

- Multi-vCenter overview with health, product, version, hypervisor, VM, datastore, and aggregated
  vCenter, hypervisor, and discovered-VM problem summaries.
- Optional nested vCenter location groups from the host macro `{$VMWARE.LOCATION}`.
- vCenter detail pages with lazy-loaded tabs, so detailed data is requested only when opened.
- Overview embeds the standard Zabbix Problems widget table in recent-problems mode with operational
  data shown separately, scoped to the vCenter and its discovered hypervisor and VM hosts.
- The embedded Problems table exposes the standard dashboard-widget hooks, including compatibility
  with the Detail action provided by `Custom-Problem-Analysis` when that module is installed.
- Hypervisor inventory with:
  - datacenter and cluster placement;
  - connection and VMware health state;
  - active problems linked to **Monitoring → Problems**;
  - 24-hour CPU history, memory utilization, VM count, uptime, ESXi version, vendor, and model;
  - native Zabbix host popup with an additional Sensors entry.
- Discovered VM inventory with:
  - inventory notes and hypervisor, datacenter, and cluster placement;
  - power and runtime state;
  - active problems;
  - 24-hour CPU history, memory, committed storage, VMware Tools, snapshots, and uptime;
  - native Zabbix host popup for dashboards, problems, latest data, graphs, inventory, and
    permitted configuration pages.
- Cluster overview with separate hypervisor-reported and Zabbix-discovered VM counts.
- Cluster details with current CPU and memory capacity, utilization, hardware/software composition,
  datastore information, sensor summaries, active problems, and the cluster's hypervisors.
- Datastore overview with expandable per-hypervisor attachments below each unique datastore,
  including capacity, free space, latency, IOPS, and multipath information.
- Hardware sensor page with current readings and units, VMware value-map states, unhealthy-state
  details, related problems, collection errors, sorting, and multi-type checkbox filtering.
- vCenter Issues view based on the alarm data already collected for the vCenter.
- Server-side pagination for large VM and datastore inventories. Sensor pagination is applied after
  its name, status, and type filters.
- Pagination size follows the **Rows per page** setting in the user's Zabbix profile.
- Zabbix blue, dark, and high-contrast dark theme support.

## Module scope

This repository contains a frontend module only. It:

- reads hosts, items, history, problems, inventory, tags, and low-level discovery relationships
  already stored in Zabbix;
- identifies vCenter hosts by the monitored `vmware.version[{$VMWARE.URL}]` item rather than by a
  host or template name;
- associates hypervisors and VMs with the vCenter discovery rule that created them;
- supports multiple vCenters and respects the current user's Zabbix permissions;
- does not collect data directly from vCenter, store VMware credentials, create hosts, run VMware
  discovery, or modify monitored objects;
- does not replace or bundle the official Zabbix VMware templates.

Hypervisors and VMs must be created by the host prototypes of the official VMware template.
Manually created hosts linked directly to the hypervisor or guest templates cannot be associated
reliably with a vCenter because they have no originating discovery relationship.

## Requirements

| Component | Requirement |
|---|---|
| Zabbix server | 7.0 |
| Zabbix frontend | 7.0 |
| Monitoring | A configured vCenter host using the official Zabbix VMware template |
| Permissions | Access to Monitoring and Latest data for the VMware hosts the user should see |

## Installation

1. Download or clone this repository into the Zabbix frontend module directory. A typical path is:

   ```text
   /usr/share/zabbix/modules/vmware_monitoring
   ```

2. Ensure the directory and its files are readable by the web server user.
3. In Zabbix, open **Administration → General → Modules**.
4. Select **Scan directory**.
5. Enable **VMware Monitoring**.
6. Open **Monitoring → VMware**.

No Composer, npm, Node.js, compilation, database migration, or additional VMware credentials are
required. The module is loaded directly by the Zabbix frontend.

When upgrading, replace the module files, scan the module directory again, and reload the browser
page. Do not copy the repository's `.git` directory into a packaged production installation.

## Usage

1. Configure VMware monitoring with the official Zabbix 7.0 VMware template and wait for its
   hypervisor and VM discovery rules to create hosts.
2. Open **Monitoring → VMware** to see every accessible vCenter that has a monitored
   `vmware.version[{$VMWARE.URL}]` item.
3. Select a vCenter and use the Overview, Clusters, Hypervisors, Discovered VMs, Datastores, and
   vCenter Issues tabs.
4. Select hypervisor or VM names for links to native Zabbix pages. Hypervisor menus also provide
   access to their sensor details.
5. Use the filters before paging through large environments. Filter and sort selections are kept
   when changing pages.

To group the main vCenter table, define `{$VMWARE.LOCATION}` directly on a vCenter host. A simple
value such as `USA` creates one group. Comma-separated values create nested groups, for example
`USA, New York`. vCenters without the macro remain ungrouped.

Hardware sensors appear only when the official template has discovered the corresponding sensor
items. Enable the `{$VMWARE.HV.SENSOR.DISCOVERY}` macro for the required hypervisors and allow the
discovery rule and items to collect their first values.

Current readings and VMware status-detail tooltips require the compact dependent text item
`vmware.hv.sensors.data` in the adapted VMware template. It stores one hour of compact sensor data
per hypervisor; without it, the sensor health states and problems remain available but the
**Current reading** column displays `-`.

Counts labelled **Virtual machines** or **Total VMs** come from the VM totals reported by
hypervisors. **Discovered VMs** counts only VM hosts created by Zabbix discovery; the two values can
differ when discovery filters, permissions, or data freshness differ.

## Acknowledgements

Module scaffolding and the original UI/UX approach were based on
[Monzphere/zabbix-module-docker](https://github.com/Monzphere/zabbix-module-docker).
