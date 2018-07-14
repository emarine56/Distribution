import cloneDeep from 'lodash/cloneDeep'
import merge from 'lodash/merge'

import {makeInstanceReducer, combineReducers} from '#/main/app/store/reducer'
import {makeListReducer} from '#/main/core/data/list/reducer'

import {
  EXPLORER_INITIALIZE,
  DIRECTORY_CHANGE,
  DIRECTORY_TOGGLE_OPEN,
  DIRECTORIES_LOAD
} from '#/main/core/resource/explorer/store/actions'

import {selectors} from '#/main/core/resource/explorer/store/selectors'

function updateDirectory(directories, oldDir, newDir) {
  for (let i = 0; i < directories.length; i++) {
    if (directories[i].id === oldDir.id) {
      const updatedDirs = cloneDeep(directories)
      updatedDirs[i] = newDir

      return updatedDirs
    } else if (directories[i].children) {
      const updatedDirs = cloneDeep(directories)
      updatedDirs[i].children = updateDirectory(directories[i].children, oldDir, newDir)

      return updatedDirs
    }
  }

  return directories
}

const defaultState = {
  initialized: false,
  root: null,
  current: null,
  directories: []
}

const initializedReducer = makeInstanceReducer(defaultState.initialized, {
  [EXPLORER_INITIALIZE]: () => true
})

const rootReducer = makeInstanceReducer(defaultState.root, {
  [EXPLORER_INITIALIZE]: (state, action) => action.root
})

const currentReducer = makeInstanceReducer(null, {
  [EXPLORER_INITIALIZE]: (state, action) => action.current,
  [DIRECTORY_CHANGE]: (state, action) => action.directory
})

const directoriesReducer = makeInstanceReducer([], {
  [EXPLORER_INITIALIZE]: (state, action) => action.root ? [action.root] : [],
  [DIRECTORIES_LOAD]: (state, action) => {
    if (!action.parent) {
      return action.directories
    }

    const updatedParent = cloneDeep(selectors.directory(state, action.parent.id))

    // set parent children
    updatedParent._loaded = true
    updatedParent.children = action.directories

    return updateDirectory(state, action.parent, updatedParent)
  },
  [DIRECTORY_TOGGLE_OPEN]: (state, action) => {
    const updatedDirectory = cloneDeep(selectors.directory(state, action.directory.id))

    updatedDirectory._opened = action.opened

    return updateDirectory(state, action.directory, updatedDirectory)
  }
})

/**
 * Creates reducers for resource explorer.
 *
 * @param {string} explorerName - the name of the explorer.
 * @param {object} initialState - the initial state of the explorer instance.
 *
 * @return {function}
 */
function makeResourceExplorerReducer(explorerName, initialState = {}) {
  const explorerState = merge({}, defaultState, initialState)

  return combineReducers({
    initialized: initializedReducer(explorerName, explorerState.initialized),

    /**
     * The root of the explorer instance.
     *
     * The user will not be able to go higher in the directory structure
     * (most of the times it's used to store the WS root).
     */
    root: rootReducer(explorerName, explorerState.root),

    /**
     * The current displayed directory.
     */
    current: currentReducer(explorerName, explorerState.current),

    /**
     * The list of available directories.
     *
     * Each level is loaded on demand when the user uses directories nav,
     * so you can not assert this contains the full directories list.
     */
    directories: directoriesReducer(explorerName, explorerState.directories),

    /**
     * The list of resources for the current directory.
     */
    resources: makeListReducer(`${explorerName}.resources`)
  })
}

export {
  makeResourceExplorerReducer
}