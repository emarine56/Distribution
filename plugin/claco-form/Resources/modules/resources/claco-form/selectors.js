import {createSelector} from 'reselect'

const resource = state => state.resource
const isAnon = state => state.isAnon
const user = state => state.user
const canEdit = state => state.canEdit
const params = state => state.resource.details
const visibleFields = state => state.fields.filter(f => !f.hidden)
const template = state => state.resource.template
const useTemplate = state => state.resource.details['use_template']
const getParam = (state, property) => state.resource.details[property]
const currentEntry = state => state.currentEntry
const myEntriesCount = state => state.myEntriesCount
const canAdministrate = state => state.resourceNode.rights.current.administrate
const categories = state => state.categories

const canSearchEntry = createSelector(
  canEdit,
  isAnon,
  params,
  (canEdit, isAnon, params) => canEdit || !isAnon || params['search_enabled']
)

const isCurrentEntryOwner = createSelector(
  isAnon,
  user,
  currentEntry,
  (isAnon, user, currentEntry) => {
    return !isAnon && user && currentEntry && currentEntry.user && currentEntry.user.id === user.id
  }
)

const isCurrentEntryManager = createSelector(
  isAnon,
  user,
  currentEntry,
  (isAnon, user, currentEntry) => {
    let isManager = false

    if (!isAnon && user && currentEntry && currentEntry.categories) {
      currentEntry.categories.forEach(category => {
        if (!isManager && category.managers) {
          category.managers.forEach(manager => {
            if (manager.id === user.id) {
              isManager = true
            }
          })
        }
      })
    }

    return isManager
  }
)

const canManageCurrentEntry = createSelector(
  isAnon,
  user,
  canEdit,
  currentEntry,
  (isAnon, user, canEdit, currentEntry) => {
    let canManage = canEdit

    if (!canManage && !isAnon && user && currentEntry && currentEntry.categories) {
      currentEntry.categories.forEach(category => {
        if (!canManage && category.managers) {
          category.managers.forEach(manager => {
            if (manager.id === user.id) {
              canManage = true
            }
          })
        }
      })
    }

    return canManage
  }
)

const canEditCurrentEntry = createSelector(
  canEdit,
  params,
  isCurrentEntryOwner,
  canManageCurrentEntry,
  (canEdit, params, isCurrentEntryOwner, canManageCurrentEntry) => {
    return canEdit || (params['edition_enabled'] && isCurrentEntryOwner) || canManageCurrentEntry
  }
)

const canAddEntry = createSelector(
  canEdit,
  isAnon,
  params,
  myEntriesCount,
  (canEdit, isAnon, params, myEntriesCount) => {
    return canEdit || (
      params['creation_enabled'] &&
      !(isAnon && params['max_entries'] > 0) &&
      !(params['max_entries'] > 0 && myEntriesCount >= params['max_entries'])
    )
  }
)

const canOpenCurrentEntry = createSelector(
  canEdit,
  params,
  currentEntry,
  isCurrentEntryOwner,
  canManageCurrentEntry,
  (canEdit, params, currentEntry, isCurrentEntryOwner, canManageCurrentEntry) => {
    return canEdit || (
      currentEntry && (
        (params['search_enabled'] && currentEntry.status === 1) ||
        isCurrentEntryOwner ||
        canManageCurrentEntry
      )
    )
  }
)

const isCategoryManager = createSelector(
  user,
  categories,
  (user, categories) => {
    return user.id > 0 && categories.filter(c => c.managers.find(m => m.id === user.id)).length > 0
  }
)

export const selectors = {
  resource,
  canEdit,
  isAnon,
  params,
  canSearchEntry,
  visibleFields,
  template,
  useTemplate,
  getParam,
  isCurrentEntryOwner,
  isCurrentEntryManager,
  canManageCurrentEntry,
  canEditCurrentEntry,
  canAddEntry,
  canOpenCurrentEntry,
  canAdministrate,
  isCategoryManager
}