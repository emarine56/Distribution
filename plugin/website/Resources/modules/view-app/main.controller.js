/**
 * Created by ptsavdar on 15/03/16.
 */

export default class MainController {
  constructor(websiteData) {
    this.menu = websiteData.pages[ 0 ]
    this.options = websiteData.options
    this.contentHeight = 400
    this.basePath = websiteData.path
    if (websiteData.options.menuOrientation == 'vertical') {
      this.pushMenuOptions = {
        containersToPush: [],
        wrapperClass: 'multilevelpushmenu_wrapper',
        menuInactiveClass: 'multilevelpushmenu_inactive',
        menuWidth: this.options.menuWidth,
        direction: 'rtl',
        backItemIcon: 'fa fa-angle-left',
        groupIcon: 'fa fa-angle-right',
        backText: 'Back',
        mode: 'cover',
        overlapWidth: 0,
        buildHref: this.buildHref.bind(this)
      }
    } else {
      this.flexnavOptions = {
        basePath: websiteData.path,
        breakpoint: 800,
        currentPage: websiteData.currentPage||null,
        buildHref: this.buildHref.bind(this)
      }
    }
  }

  buildHref(page) {
    if (page.type === 'url' && page.target === 1) {
      return page.url
    } else if (page.type === 'resource' && page.target === 1) {
      return window.Routing.generate('claro_resource_open', {
        'resourceType': page.resourceNodeType,
        'node': page.resourceNodeId
      })
    }
    return this.basePath + '/' + page.id
  }
}

MainController.$inject = ['website.data']