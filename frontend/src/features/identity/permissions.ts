export function hasPermission(permission: string) {
  try {
    const permissions = JSON.parse(sessionStorage.getItem('hivefinance.permissions') ?? '[]') as unknown
    const roles = JSON.parse(sessionStorage.getItem('hivefinance.roles') ?? '[]') as unknown
    return (Array.isArray(roles) && (roles.includes('owner') || roles.includes('admin')))
      || (Array.isArray(permissions) && permissions.includes(permission))
  } catch {
    return false
  }
}
