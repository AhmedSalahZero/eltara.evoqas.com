// ══════════════════════════════════════════════════════════════════
//  El Tara — Menus of the three portals
//  Location: resources/js/Layouts/navigation.js
//
//  The sidebar items, in the demo's order (Scope §3, §6, §7).
//  Each item: { label (translation key), icon, route, params?,
//               permission?, phase2? }
//  · permission → the item is shown only to people who hold it
//  · phase2: true → a placeholder for a later phase (no page yet)
//  A plain string starts a new section heading.
// ══════════════════════════════════════════════════════════════════

export const adminMenu = [
    'nav.platform',
    { label: 'nav.overview', icon: 'dash', route: 'admin.dashboard', exact: true },
    { label: 'nav.companies', icon: 'building', route: 'admin.companies.index' },
];

export const officeMenu = [
    'nav.main',
    { label: 'nav.dashboard', icon: 'dash', route: 'office.home', exact: true },
    { label: 'nav.requests', icon: 'file', permission: 'client_requests.view', route: 'office.client-requests.index' },
    { label: 'nav.trips', icon: 'route', permission: 'trips.view', route: 'office.trips.index' },
    { label: 'nav.wallets', icon: 'wallet', permission: 'wallet_transfers.view', route: 'office.wallets.index' },
    'nav.operations',
    { label: 'nav.vehicles', icon: 'truck', permission: 'vehicles.view', route: 'office.vehicles.index' },
    { label: 'nav.drivers', icon: 'idcard', permission: 'drivers.view', route: 'office.drivers.index' },
    { label: 'nav.customers', icon: 'building', permission: 'customers.view', route: 'office.customers.index' },
    { label: 'nav.routes', icon: 'pin', permission: 'customers.view', route: 'office.routes.index' },
    { label: 'nav.fuel', icon: 'fuel', permission: 'fuel.view', route: 'office.fuel.index' },
    { label: 'nav.maintenance', icon: 'wrench', phase2: true },
    'nav.finance',
    { label: 'nav.advances', icon: 'hand', permission: 'driver_advances.view', route: 'office.advances.index' },
    { label: 'nav.invoices', icon: 'link', permission: 'invoice_links.view', route: 'office.invoices.index' },
    { label: 'nav.close', icon: 'lock', permission: 'month_close.view', route: 'office.close.index' },
    { label: 'nav.reports', icon: 'chart', permission: 'reports.view', route: 'office.reports.index' },
    'nav.admin',
    { label: 'nav.users', icon: 'key', permission: 'users.view', route: 'office.users.index' },
    { label: 'nav.settings', icon: 'settings', permission: 'settings.view', route: 'office.settings.show' },
    // Step 7 — only the company admin sees the audit log (Scope §12).
    { label: 'nav.audit', icon: 'shield', adminOnly: true, route: 'office.audit.index' },
];

export const clientMenu = [
    'nav.clientPortal',
    { label: 'nav.clientHome', icon: 'dash', route: 'client.home', exact: true },
    { label: 'nav.clientNew', icon: 'plus', route: 'client.requests.create' },
    { label: 'nav.clientRequests', icon: 'file', route: 'client.requests.index', exact: true },
    { label: 'nav.clientShipments', icon: 'truck', route: 'client.shipments.index' },
    { label: 'nav.clientCash', icon: 'hand', route: 'client.cash.index' },
    'nav.account',
    { label: 'nav.clientStatement', icon: 'receipt', route: 'client.statement.index' },
    { label: 'nav.clientPrices', icon: 'tag', route: 'client.prices.index' },
    { label: 'nav.clientRatings', icon: 'flag', route: 'client.feedback.index' },
    { label: 'nav.clientUsers', icon: 'users', route: 'client.team.index' },
];
