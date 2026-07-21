import type { SectionNavItem } from '@/components/shared/SectionNav'

/** Sub-navigation shown at the top of every Administration page — same shape as masterDataNav. */
export const administrationNav: SectionNavItem[] = [
  { label: 'Company', path: '/administration/company', permission: 'company.view' },
  { label: 'Users', path: '/administration/users', permission: 'user.view' },
  { label: 'Roles & Permissions', path: '/administration/roles', permission: 'role.view' },
  { label: 'Audit Log', path: '/administration/audit-log', permission: 'audit_log.view' },
]
