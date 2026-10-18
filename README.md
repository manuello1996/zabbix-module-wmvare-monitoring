# VMware Monitoring for Zabbix 7.0

Explore VMware infrastructure using the hosts, items, history, inventory and problems already
collected by Zabbix. The module reads the Zabbix API with the signed-in user's permissions;
Zabbix server or proxy collectors connect to vCenter.

## Features

| View | Information and actions |
|---|---|
| vCenters | Health, version, inventory notes, problems and resource counts, with optional nested location groups. |
| vCenter overview | Product information, discovered resources and the native Zabbix Problems table. |
| Hypervisors | Placement, connection, health, problems, 24-hour CPU sparklines, memory, VM count, uptime and ESXi version. |
| Discovered VMs | Notes, placement, power and runtime states, problems, CPU history, memory, storage, VMware Tools, snapshots and uptime. |
| Clusters | Health and resource counts, with detail pages for capacity, utilization, configuration, sensors, problems and member hypervisors. |
| Datastores | Unique datastores with expandable per-hypervisor capacity, free space, latency, IOPS and multipath measurements. |
| Issues and Problems | VMware alarm items and native Zabbix problem information scoped to the selected environment. |
| Hypervisor details | Hardware and software information, capacity, runtime metrics, datastores, discovered VMs, problems and sensors. |

Item-backed values open the native Zabbix menu: **Item**, **Graph** for numeric items,
**Values**, and **500 latest values**. Zabbix determines configuration permissions and
history availability. Native item and host dialogs allow authorized configuration changes.
Calculated totals and physical sensor readings derived from the compact sensor payload have
no individual source-item menu.

Detail tabs load on demand. Search and sorting are available in supported inventories;
pagination follows the user's **Rows per page** setting. The module supports Zabbix blue,
dark and high-contrast dark themes and works independently of other monitoring modules.

## Screenshots

The screenshots below use the synthetic VMware demo environment.

### vCenter inventory

Grouped vCenters with health, problem summaries, resource counts, and inventory notes.

![vCenter inventory grouped by location, showing health, problems, resource counts, and notes](img/vCenters.png)

### vCenter overview

Product information, discovered resources, and the native Zabbix Problems table.

![vCenter overview with product details, resource counts, and active Zabbix problems](img/Overview.png)

### Clusters

Cluster health, hypervisor and VM counts, and memory usage and capacity.

![Cluster inventory showing health, hypervisor and VM counts, and memory capacity](img/cluster.png)

### Hypervisors

Host placement, connection and health states, CPU history, memory utilization, and uptime.

![Hypervisor inventory with CPU sparklines, memory utilization, health, and uptime](img/Hypervisors.png)

### Hypervisor details

Hardware and software information, capacity and utilization, and access to related resources.

![Hypervisor detail page showing hardware information, CPU and memory capacity, and resource tabs](img/hypervisor-detail.png)

## Requirements

| Component | Requirement |
|---|---|
| Zabbix | Server or proxy and frontend on version 7.0. |
| Collection | VMware collectors enabled on the server or proxy responsible for the vCenter. |
| Templates | Configured `VMware`, `VMware Hypervisor` and `VMware Guest` monitoring templates. |
| Discovery | Hypervisor and VM hosts created by the vCenter template's host prototypes. |
| Access | Permission to use Monitoring and Latest data, plus access to the relevant hosts. Configuration actions require additional Zabbix permissions. |

The module identifies vCenters through the monitored item
`vmware.version[{$VMWARE.URL}]`, rather than a host or template name. Hypervisors and VMs
are associated through their originating low-level discovery rules. Manually created hosts
linked only to a hypervisor or guest template lack that relationship and may not appear.

The frontend module needs no Composer, npm, compilation or extra VMware credentials.
Its optional demo tools have separate Python and Docker requirements.

## Installation and upgrade

1. Copy this module into a directory under the Zabbix frontend's `modules` folder, for example
   `/usr/share/zabbix/modules/vmware_monitoring`.
2. Ensure the frontend can read the module files.
3. Open **Administration → General → Modules**, select **Scan directory**, and enable
   **VMware Monitoring**.
4. Open **Monitoring → VMware**.

For an upgrade, replace the module files, scan the directory again and reload the browser.
Exclude the repository's `.git` directory from production packages.

## Using the module

1. Configure VMware collection in Zabbix and wait for discovery and the first samples.
2. Open **Monitoring → VMware**. The main page offers **vCenters**, **Issues** and **Problems**.
3. Select a vCenter to browse **Overview**, **Clusters**, **Hypervisors**, **Discovered VMs**,
   **Datastores** and **vCenter Issues**.
4. Click a hypervisor name to open its detail page; right-click it for the native Zabbix host
   menu. VM names open the native host menu. Hypervisor host menus also include **Sensors**.
5. Click a cluster name for cluster details, or expand a datastore to load attachment metrics.
6. Click an item-backed value for its configuration, graph and history actions.

**Refresh vCenters** updates the main inventory; **Refresh vCenter** clears the selected
view's cached data and reloads the stored Zabbix results. These controls do not execute
VMware checks or force discovery on the server or proxy.

Monitoring payloads are cached in private temporary files on the frontend, scoped to the
user and login session. Only a small invalidation token enters the session cookie. When
private storage is unavailable, data is read without that server cache.

### Location groups and inventory notes

Define the host macro `{$VMWARE.LOCATION}` directly on a vCenter:

```text
USA
USA, New York
USA, New York, Rack 2
```

Comma-separated values create nested groups. vCenters without the macro remain ungrouped.
Click a group's arrow or name to collapse it; the state is saved per Zabbix user.

Group totals include all filtered descendant vCenters, including those on other pages.
Shared datastore identities and problem events are deduplicated. Child groups retain their
own collapse state when a parent is reopened.

Populate host inventory **Notes** (`{INVENTORY.NOTES}`) to display descriptive information
in the vCenter and VM inventories and hypervisor details.

### Hardware sensors

Enable `{$VMWARE.HV.SENSOR.DISCOVERY}=true` on the relevant hypervisor and allow discovery
and collection to complete. The Sensors tab provides name, status and type filters, current
readings, health states, collection errors and related problems.

Physical readings and health-detail tooltips require the additional dependent text item
`vmware.hv.sensors.data` from the adapted monitoring template. Without that item, sensor
health and problems remain available while **Current reading** displays `-`. Installing
the UI module does not add or modify monitoring items or templates.

### Understanding counts and missing values

**Virtual machines** / **Total VMs** are hypervisor-reported totals. **Discovered VMs** counts
VM hosts created by Zabbix discovery. Filters, permissions and sample availability can make
these counts differ.

Missing values can indicate absent items, unavailable samples or collection errors. Inspect
native **Latest data** and the item's error before interpreting an empty value as healthy.
CPU sparklines require retained history and build up after monitoring starts.

## Demo without VMware hardware

The optional [VMware mock setup guide](dev/mock-vmware/README.md) provides a removable
SOAP/REST simulation using `vcsim` and an isolated Zabbix proxy. The dataset contains one
vCenter, one cluster, two hypervisors, four VMs and two datastores.

Run these commands from the module directory:

```powershell
python dev/mock-vmware/demo.py up
python dev/mock-vmware/demo.py status
python dev/mock-vmware/demo.py scenario --scenario degraded
python dev/mock-vmware/demo.py scenario --scenario unresponsive
python dev/mock-vmware/demo.py scenario --scenario healthy
python dev/mock-vmware/demo.py down
```

Setup requires Python 3.10+, Docker Compose, the three installed VMware templates and
Zabbix API permissions to manage the isolated fixture. Enter the Zabbix password when
prompted, or use `ZABBIX_PASSWORD` / `ZABBIX_API_TOKEN`. Allow **2–5 minutes** for discovery
and initial samples; setup prints a link that refreshes the module's vCenter inventory.

The helper clones the installed templates and creates demo objects; it preserves the
original templates and existing server configuration. `down` removes the owned fixture
and its disposable monitoring history, with safeguards against removing unrelated objects.

The **degraded** scenario makes vCenter appliance health red and exercises the template's
health trigger. **Unresponsive** exercises real SDK/REST timeouts while keeping scenario
controls available. The simulator does not implement every vSphere counter or hardware
failure; some device-specific metrics can remain unsupported. See the setup guide for
network settings, endpoints, credentials, coverage limits and cleanup details.

## Acknowledgements

Module scaffolding and the original UI/UX approach were based on
[Monzphere/zabbix-module-docker](https://github.com/Monzphere/zabbix-module-docker).
