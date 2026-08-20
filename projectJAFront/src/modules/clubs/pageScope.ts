export type ClubPageScope = {
  isMiClub: boolean
  isSessionClub: boolean
  listRoute: 'mi-club' | 'clubs'
  createRoute: 'mi-club.create' | 'clubs.create'
  editRoute: 'mi-club.edit' | 'clubs.edit'
  viewPerm: 'mi_club.view' | 'clubs.view'
  createPerm: 'mi_club.create' | 'clubs.create'
  updatePerm: 'mi_club.update' | 'clubs.update'
  deletePerm: 'mi_club.delete' | 'clubs.delete'
  directorsPerm: 'mi_club.manage_directors' | 'clubs.manage_directors'
}

export function clubPageScope(routeName: string | symbol | null | undefined): ClubPageScope {
  const name = String(routeName ?? '')
  const isMiClub = name === 'mi-club' || name.startsWith('mi-club.')

  return {
    isMiClub,
    isSessionClub: name === 'mi-club',
    listRoute: isMiClub ? 'mi-club' : 'clubs',
    createRoute: isMiClub ? 'mi-club.create' : 'clubs.create',
    editRoute: isMiClub ? 'mi-club.edit' : 'clubs.edit',
    viewPerm: isMiClub ? 'mi_club.view' : 'clubs.view',
    createPerm: isMiClub ? 'mi_club.create' : 'clubs.create',
    updatePerm: isMiClub ? 'mi_club.update' : 'clubs.update',
    deletePerm: isMiClub ? 'mi_club.delete' : 'clubs.delete',
    directorsPerm: isMiClub ? 'mi_club.manage_directors' : 'clubs.manage_directors',
  }
}
