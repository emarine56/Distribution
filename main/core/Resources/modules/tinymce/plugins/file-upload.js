import tinymce from 'tinymce/tinymce'
import invariant from 'invariant'

import {makeId} from '#/main/core/scaffolding/id'
import {url} from '#/main/app/api'
import {trans} from '#/main/core/translation'

import {MODAL_TINYMCE_UPLOAD} from '#/main/core/tinymce/modals/upload'

// TODO : make loaders work
// TODO : remove placeholder on upload cancel

/**
 * Open a file upload form from a TinyMCE editor.
 */
function openFileUpload(editor) {
  // We need to generate an anchor in the content to know where to put the file we will upload.
  // For now, the resource picker will unmount the TinyMCE editor when shown in a modal
  // so we will loose the cursor position.
  const placeholder = `<span id="file-upload-${makeId()}" style="display: none;">${trans('file')}</span>`
  editor.insertContent(placeholder)

  editor.setProgressState(true)

  editor.settings.showModal(MODAL_TINYMCE_UPLOAD, {
    add: (newResourceNode) => {
      fetch(
        url(['claro_resource_embed', {type: newResourceNode.meta.type, id: newResourceNode.id}]), {
          credentials: 'include'
        })
        .then(response => {
          if (response.ok) {
            return response.text()
          }
        })
        .then(responseText => {
          // retrieve the editor which have requested the picker
          // ATTENTION : we don't reuse instance from func params because it could have been removed
          // when tinyMCE is rendered in a modal
          const initiator = /*tinymce.get(editor.id) */ tinymce.activeEditor
          if (initiator) {
            let content = initiator.getContent()
            content = content.replace(placeholder, responseText)

            // replace content in editor
            initiator.setContent(content)
            initiator.setProgressState(false)
          }

          initiator.fire('change')
        })
        .catch((error) => {
          // creates log error
          invariant(false, error.message)

          const initiator = tinymce.get(editor.id)
          if (initiator) {
            // displays generic error in ui
            initiator.notificationManager.open({type: 'error', text: trans('error_occured')})
            initiator.setProgressState(false)
          }
        })
    }
  })
}

// Register new plugin
tinymce.PluginManager.add('file-upload', (editor) => {
  // provides an insert menu item
  editor.addMenuItem('file-upload', {
    icon: 'file-upload',
    text: trans('file'),
    context: 'insert',
    onclick: () => openFileUpload(editor)
  })

  // provides a toolbar button
  editor.addButton('file-upload', {
    icon: 'file-upload',
    tooltip: trans('upload'),
    onclick: () => openFileUpload(editor)
  })
})
