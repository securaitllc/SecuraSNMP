// The top bar. Eight things an operator reaches for all day are top-level; the
// rest sit behind two menus so the bar reads at a glance at 1440px.
export default [
  { title: 'Dashboard', to: { name: 'root' } },
  { title: 'Sites', to: { name: 'sites' } },
  { title: 'Topology', to: { name: 'topology' } },
  { title: 'Devices', to: { name: 'devices' } },
  { title: 'Circuits', to: { name: 'circuits' } },
  { title: 'Alarms', to: { name: 'alarms' } },
  { title: 'Flows', to: { name: 'flows' } },
  { title: 'Reports', to: { name: 'reports' } },
  {
    title: 'Tools',
    children: [
      { title: 'MAC Search', to: { name: 'mac-search' } },
      { title: 'IPAM', to: { name: 'ipam' } },
      { title: 'ISP Providers', to: { name: 'isp-providers' } },
      { title: 'Anomalies', to: { name: 'anomalies' } },
      { title: 'Vulnerabilities', to: { name: 'vulnerabilities' } },
      { title: 'Discovery', to: { name: 'discovery' } },
      { title: 'Syslog', to: { name: 'syslog' } },
      { title: 'Wallboard', to: { name: 'wallboard' } },
      { title: 'OSINT', to: { name: 'osint' }, superAdmin: true },
    ],
  },
  {
    title: 'Admin',
    children: [
      { title: 'Users', to: { name: 'users' } },
      { title: 'Notifications', to: { name: 'notifications' } },
      { title: 'SSH Credentials', to: { name: 'ssh-credentials' } },
      { title: 'Device Import', to: { name: 'device-import' } },
      { title: 'Circuit Import', to: { name: 'circuit-import' } },
      { title: 'Audit Log', to: { name: 'audit' } },
    ],
  },
]
