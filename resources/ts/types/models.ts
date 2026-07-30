export interface Site {
  id: number
  name: string
  site_type?: 'hub' | 'branch'
  hub_site_id?: number | null
  hub_site_ids?: number[]
  address: string | null
  latitude: number | null
  longitude: number | null
  notes: string | null
  site_number?: string | null
  region?: string | null
  main_phone?: string | null
  fax?: string | null
  gm_name?: string | null
  gm_phone?: string | null
  gm_ext?: string | null
  om_name?: string | null
  om_phone?: string | null
  om_ext?: string | null
  sm_name?: string | null
  sm_phone?: string | null
  sm_ext?: string | null
  devices_count?: number
  circuits_count?: number
  created_at: string
  updated_at: string
}

export interface SiteOverviewDevice {
  id: number
  name: string
  ip_address: string | null
  vendor: string | null
  model: string | null
  os_version: string | null
  role: string | null
  status: string | null
  serial_number: string | null
  cpu_pct: number | null
  mem_pct: number | null
  temperature_c: number | null
  uptime_seconds: number | null
  interfaces_total: number
  interfaces_down: number
  active_alarms: number
}

export interface SiteOverviewCircuit {
  id: number
  isp_name: string | null
  circuit_id: string | null
  circuit_type: string | null
  monitored_ip: string | null
  status: string
  ticket_number: string | null
  support_phone: string | null
  shared_from?: string | null
}

export interface SiteOverview {
  summary: {
    devices: number
    circuits: number
    circuits_down: number
    interfaces_down: number
    active_alarms: number
    max_cpu: number | null
    max_temp: number | null
  }
  devices: SiteOverviewDevice[]
  circuits: SiteOverviewCircuit[]
}

export interface DashboardSiteHealth {
  id: number
  name: string
  latitude: number | null
  longitude: number | null
  device_count: number
  circuit_count: number
  active_alert_count: number
  health: 'good' | 'critical'
}

export interface DashboardAvailabilityRow {
  key: string
  type: 'circuit' | 'interface' | 'tunnel'
  name: string
  device_name: string | null
  status: 'up' | 'down'
  route: string
}

export interface DashboardTrafficPoint {
  recorded_at: string
  in: number
  out: number
}

export interface DashboardAlert {
  key: string
  type: 'circuit' | 'interface' | 'tunnel' | 'tunnel-quality' | 'next_hop' | 'alarm' | 'incident'
  /** DeviceAlarm id, present only on alerts backed by one — required for bulk clear. */
  alarm_ref?: number | null
  title: string
  subtitle: string
  detail: string
  severity: 'critical' | 'warning'
  started_at: string
  ticket_number?: string | null
  previous_ticket_number?: string | null
  support_phone?: string | null
  device_id: number | null
  device_name?: string | null
  /** The location the alert belongs to — a circuit id alone does not say where. */
  site_name?: string | null
  device_ip?: string | null
  event_id?: string | null
  alarm_db_id?: number | null
  acknowledged_at?: string | null
  acknowledged_by?: string | null
  circuit_id: number | null
  site_id?: number | null
  // Correlated incident: the individual signals that make it up.
  member_count?: number
  members?: DashboardAlert[]
  /** A whole-site outage (multiple devices unreachable) vs a single-device incident. */
  is_site_outage?: boolean
}

export interface CircuitMetric {
  id: number
  circuit_id: number
  recorded_at: string
  response_time_ms: number | null
  loss_pct?: number | null
  created_at: string
  updated_at: string
}

export interface DeviceMetric {
  id: number
  device_id: number
  recorded_at: string
  response_time_ms: number | null
  created_at: string
  updated_at: string
}

export interface DeviceVlan {
  id: number
  device_id: number
  vlan_id: number
  name: string | null
  status: 'active' | 'inactive'
  last_seen_at: string | null
  created_at: string
  updated_at: string
}

export interface SnmpTrap {
  id: number
  device_id: number | null
  source_ip: string
  trap_oid: string | null
  message: string | null
  received_at: string
  created_at: string
  updated_at: string
}

export interface DashboardSummary {
  sites: DashboardSiteHealth[]
  availability: DashboardAvailabilityRow[]
  traffic: {
    in_total: number
    out_total: number
    discards_total: number
    series: DashboardTrafficPoint[]
  }
  alerts: DashboardAlert[]
  counts: {
    sites: number
    devices: number
    circuits_down: number
    interfaces_down: number
    tunnels_down: number
  tunnels_degraded: number
    active_alarms: number
    active_alerts: number
  }
}

export interface Device {
  id: number
  site_id: number
  name: string
  ip_address: string
  next_hop_ip: string | null
  vendor: 'juniper' | 'silverpeak' | 'fortigate'
  model: string
  os_version: string | null
  serial_number: string | null
  role: 'switch' | 'edgeconnect' | 'firewall'
  ha_group?: string | null
  ha_role?: 'active' | 'standby' | null
  snmp_version: 'v2c' | 'v3' | null
  snmp_community: string | null
  snmp_v3_username: string | null
  snmp_v3_auth_key: string | null
  snmp_v3_priv_key: string | null
  ssh_username: string | null
  ssh_credential: string | null
  ssh_credential_id: number | null
  ssh_credential_name?: string | null
  status: 'active' | 'inactive'
  notes: string | null
  health?: DeviceHealth | null
  sensors?: DeviceSensor[]
  created_at: string
  updated_at: string
}

export interface DeviceHealth {
  id: number
  device_id: number
  cpu_pct: number | null
  mem_pct: number | null
  mem_reclaimable_mb?: number | null
  swap_used_mb?: number | null
  temperature_c: number | null
  uptime_seconds: number | null
  polled_at: string | null
}

export interface DeviceSensor {
  id: number
  device_id: number
  name: string
  sensor_type: string
  value: number | null
  unit: string | null
  status: string
  last_seen_at: string | null
}

export interface DeviceHealthPoint {
  recorded_at: string
  cpu_pct: number | null
  mem_pct: number | null
  temperature_c: number | null
}

export interface ManagedUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'viewer'
  is_active: boolean
}

export interface IspProvider {
  id: number
  name: string
  support_phone: string | null
  ticket_url?: string | null
  account_rep_name: string | null
  account_rep_mobile: string | null
  account_rep_phone: string | null
  account_rep_email: string | null
  notes: string | null
  circuits_count?: number
  circuits_down_count?: number
  created_at: string
  updated_at: string
}

export interface IspOverviewCircuit {
  id: number
  circuit_id: string
  circuit_type: string
  monitored_ip: string | null
  status: string
  ticket_number: string | null
}

export interface IspOverviewSite {
  site_id: number
  site_name: string
  circuits: IspOverviewCircuit[]
}

export interface TopologyNode {
  id: string
  type: 'cloud' | 'gw' | 'nexthop' | 'edge' | 'switch' | 'fw' | 'device'
  label: string
  sub: string
  status: 'up' | 'warn' | 'down'
  col: number
  ip: string | null
  model: string | null
  role: string
  device_id?: number
  circuit_id?: number
  support_phone?: string | null
  lec_name?: string | null
  lec_circuit_id?: string | null
  ha_role?: string | null
  tunnels?: string | null
  serial?: string | null
  health?: {
    cpu_pct: number | null
    mem_pct: number | null
    temperature_c: number | null
    uptime_seconds: number | null
  } | null
  ha?: boolean
  ha_members?: { name: string, role: string | null, status: 'up' | 'down' }[] | null
  tunnel_hubs?: { hub: string, total: number, down: number }[]
  tunnels_stale?: boolean
  lldp_endpoints?: { port: string | null, name: string, type: string, remote_port: string | null, ip?: string | null }[]
  alarmed_interfaces?: { id: number, name: string, alert_id: number | null, ticket: string | null, acknowledged: boolean, since: string | null }[]
}

export interface TopologyEdge {
  from: string
  to: string
  label: string
  status: 'up' | 'warn' | 'down'
  root?: boolean
  overlay?: boolean
  ha?: boolean
  lldp?: boolean
  stp_blocked?: boolean
}

export interface TopologyIncident {
  active: boolean
  summary: string
  symptoms: string[]
  root_type?: 'circuit' | 'edge' | 'access'
  root_label?: string
  circuit_id?: number
  device_id?: number
  support_phone?: string | null
  action?: string | null
}

export interface Topology {
  site: { id: number, name: string, address: string | null }
  nodes: TopologyNode[]
  edges: TopologyEdge[]
  incident: TopologyIncident
}

export interface OrgTopologySite {
  id: number
  name: string
  address: string | null
  site_type?: 'hub' | 'branch'
  hub_site_id?: number | null
  hub_site_ids?: number[]
  state: 'up' | 'warn' | 'crit'
  chain: { cloud: boolean, gw: boolean, edge: boolean, switch: boolean }
  summary: string
  device_count: number
}

export interface IspProviderOverview {
  summary: {
    circuits: number
    circuits_down: number
    sites_served: number
    fiber: number
    cable: number
  }
  contact: {
    support_phone: string | null
    account_rep_name: string | null
    account_rep_mobile: string | null
    account_rep_phone: string | null
    account_rep_email: string | null
  }
  sites: IspOverviewSite[]
}

export interface Circuit {
  id: number
  site_id: number
  isp_provider_id: number | null
  isp_provider?: IspProvider | null
  isp_name: string
  circuit_type: 'fiber' | 'cable' | 'lte'
  ip_assignment?: 'static' | 'dhcp'
  monitor_via?: 'icmp' | 'sdwan'
  wan_interface?: string | null
  ping_target?: string | null
  circuit_id: string
  account_number: string | null
  support_phone: string | null
  shared_site_ids?: number[]
  monitored_ip: string
  subnet: string | null
  gateway_ip?: string | null
  lec_name?: string | null
  lec_circuit_id?: string | null
  lec_support_phone?: string | null
  notes: string | null
  status: 'up' | 'down'
  last_loss_pct?: number | null
  /** Median loss over recent polls — drives the "degraded" status (spike-resistant). */
  sustained_loss_pct?: number | null
  monitoring_enabled?: boolean
  last_checked_at: string | null
  created_at: string
  updated_at: string
}

export interface CircuitAlert {
  id: number
  circuit_id: number
  started_at: string
  ended_at: string | null
  cause?: 'hard_down' | 'packet_loss' | null
  detected_loss_pct?: number | null
  ticket_number: string | null
  acknowledged_at?: string | null
  acknowledged_by?: number | null
  acknowledged_by_name?: string | null
  ack_note?: string | null
  cleared_by?: number | null
  cleared_by_name?: string | null
  clear_note?: string | null
  cleared_manually?: boolean
  dispatch_at?: string | null
  dispatch_note?: string | null
  dispatch_by?: number | null
  dispatch_by_name?: string | null
  created_at: string
  updated_at: string
}

export interface DeviceInterface {
  id: number
  device_id: number
  if_index: number
  if_name: string
  status: 'up' | 'down'
  admin_status: 'up' | 'down'
  in_octets: number
  out_octets: number
  in_discards: number
  out_discards: number
  in_discards_delta: number
  out_discards_delta: number
  in_errors: number
  out_errors: number
  in_errors_delta: number
  out_errors_delta: number
  speed_bps: number
  in_util_pct: number
  out_util_pct: number
  last_polled_at: string | null
  alarm_suppressed?: boolean
  alerts?: { id: number, ticket_number: string | null, started_at: string, acknowledged_at: string | null }[]
  device?: { id: number, name: string } | null
  created_at: string
  updated_at: string
}

export interface InterfaceAlert {
  id: number
  device_interface_id: number
  started_at: string
  ended_at: string | null
  created_at: string
  updated_at: string
  /** Workflow fields — present since the ack/clear workflow was added. */
  ticket_number?: string | null
  severity?: 'critical' | 'warning' | 'info'
  acknowledged_at?: string | null
  ack_note?: string | null
  clear_note?: string | null
  cleared_manually?: boolean
  /**
   * Eager-loaded on the device history endpoint. Note that the relation shares a
   * name with the foreign-key column and overwrites it in the payload, so these
   * arrive as objects rather than ids.
   */
  device_interface?: { id: number, if_name: string } | null
  acknowledged_by?: { id: number, name: string } | number | null
  cleared_by?: { id: number, name: string } | number | null
}

export interface DeviceAlarm {
  id: number
  device_id: number
  alarm_id: string
  description: string
  severity?: 'critical' | 'warning' | 'info'
  ticket_number?: string | null
  first_seen_at: string
  cleared_at: string | null
  acknowledged_at?: string | null
  acknowledged_by?: number | null
  acknowledged_by_name?: string | null
  ack_note?: string | null
  cleared_by?: number | null
  cleared_by_name?: string | null
  clear_note?: string | null
  cleared_manually?: boolean
  created_at: string
  updated_at: string
}

export interface Tunnel {
  id: number
  device_id: number
  tunnel_name: string
  status: 'up' | 'down'
  in_discards: number
  out_discards: number
  in_discards_delta: number
  out_discards_delta: number
  last_checked_at: string | null
  created_at: string
  updated_at: string
}

export interface TunnelAlert {
  id: number
  tunnel_id: number
  started_at: string
  ended_at: string | null
  created_at: string
  updated_at: string
}

export interface NextHopAlert {
  id: number
  device_id: number
  started_at: string
  ended_at: string | null
  created_at: string
  updated_at: string
}

export interface InterfaceMetric {
  id: number
  device_interface_id: number
  recorded_at: string
  status: 'up' | 'down'
  in_octets_delta: number
  out_octets_delta: number
  in_discards_delta: number
  out_discards_delta: number
}

export interface TunnelMetric {
  id: number
  tunnel_id: number
  recorded_at: string
  status: 'up' | 'down'
  in_discards_delta: number
  out_discards_delta: number
}

export interface SnmpCredential {
  id: number
  name: string
  snmp_version: 'v2c' | 'v3'
  has_community: boolean
  snmp_v3_username: string | null
  has_v3_auth_key: boolean
  has_v3_priv_key: boolean
  notes: string | null
  created_at: string
  updated_at: string
}

export interface DiscoveredDevice {
  id: number
  ip_address: string
  sys_name: string | null
  sys_descr: string | null
  sys_object_id: string | null
  vendor: string | null
  model: string | null
  serial_number: string | null
  suggested_role: string | null
  suggested_site_id: number | null
  suggested_site?: { id: number, name: string } | null
  matched_device_id: number | null
  matched_device?: { id: number, name: string } | null
  status: 'new' | 'existing' | 'imported' | 'ignored'
}

export interface DiscoveryScan {
  id: number
  name: string | null
  subnets: string[]
  snmp_credential_id: number
  status: 'pending' | 'running' | 'completed' | 'failed'
  hosts_total: number
  hosts_responded: number
  devices_found: number
  started_at: string | null
  finished_at: string | null
  error: string | null
  credential?: { id: number, name: string } | null
  new_count?: number
  imported_count?: number
  discovered_devices?: DiscoveredDevice[]
  created_at: string
  updated_at: string
}

export interface SshCredential {
  id: number
  name: string
  username: string
  has_password: boolean
  notes: string | null
  created_at: string
  updated_at: string
}

export interface NotificationChannel {
  id: number
  name: string
  type: 'email' | 'slack' | 'webhook'
  min_severity: 'info' | 'warning' | 'critical'
  enabled: boolean
  destination: string | null
  created_at: string
  updated_at: string
}

export interface MaintenanceWindow {
  id: number
  name: string
  starts_at: string
  ends_at: string
  site_id: number | null
  device_id: number | null
  site?: { id: number, name: string } | null
  device?: { id: number, name: string } | null
  reason: string | null
  created_at: string
  updated_at: string
}

export interface NotificationLog {
  id: number
  notification_channel_id: number | null
  channel?: { id: number, name: string, type: string } | null
  event: 'open' | 'resolved'
  severity: 'info' | 'warning' | 'critical'
  subject: string
  body: string | null
  status: 'sent' | 'failed' | 'suppressed'
  error: string | null
  created_at: string
}

export interface SyslogMessage {
  id: number
  device_id: number | null
  source_ip: string
  facility: number | null
  severity: number | null
  hostname: string | null
  message: string
  received_at: string
  device?: { id: number, name: string } | null
}



export interface ToolResult {
  tool: string
  target: string
  exit_code: number | null
  output: string
}

export interface SlaRow {
  type: string
  name: string
  device: string | null
  uptime_pct: number
  downtime_seconds: number
  incidents: number
  mttr_seconds: number | null
}

export interface AuditLogEntry {
  id: number
  user_id: number | null
  user_name: string | null
  method: string
  path: string
  status: number
  ip_address: string | null
  created_at: string
}

export interface SearchResult {
  type: 'device' | 'circuit' | 'site' | 'ticket' | 'alarm' | 'endpoint'
  label: string
  sub: string | null
  route: string
}
