/**
 * Agent access consent result: the page runs inside the TYPO3 backend frame, so it moves the whole window back to the
 * agent's redirect URI (it carries the one-time code or the error). The link stays as the manual way.
 */
const link = document.querySelector('a[data-aqg-mcp-continue]');
if (link instanceof HTMLAnchorElement && /^https?:\/\//.test(link.href)) {
  (window.top ?? window).location.assign(link.href);
}
