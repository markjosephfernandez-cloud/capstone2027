# Web responsiveness review — September 12, 2026

Reviewed the PHP web interface, its shared CSS, and the templates used by the web controllers. This is a source review with automated PHP/view checks. No browser was connected, so rendered layouts, touch interactions, and actual device behavior remain unverified. The separate mobile application was outside this review.

## Findings and changes

| Area | Review result |
| --- | --- |
| Shared header and sidebar | Viewport metadata and sidebar breakpoints already exist. Browser checks for opening, closing, and short-screen navigation remain pending. |
| Shared layout | Added the missing `min-w-0` utility used by folder labels. Long breadcrumbs can wrap, pagination can wrap onto additional lines, and modal headings can shrink without pushing away the close button. |
| Login | Existing single-column layout at 800px and reduced padding at 380px. No changes made. |
| Dashboard | Existing Bootstrap column breakpoints, scrollable tables/chart, and small-screen request layout. No page-specific changes made. |
| File Management | Card grids can shrink to their container width. Folder list columns can shrink on phones, counts can wrap, and inherited folder margins no longer offset list rows. |
| Watermarking | Folder list uses two columns on phones, with the modified date hidden. Reset inherited list-card dimensions and padding. Grids can shrink to their container width. On smaller screens, preview controls flow below the image, and the image preview body can scroll. |
| Trash and deleted folders | Grid minimum widths now respect the container. Selection buttons wrap. Deleted-folder titles and content headings can wrap. |
| iFound | Selection controls wrap. Review body scrolls within the capped dialog height instead of requiring a 620px minimum. Reduced phone padding and allowed review actions/pager to wrap. Long scanner paths can wrap. |
| Metadata Passports | Existing stacked detail modal and responsive table. Analysis/generation actions now wrap; pagination benefits from the shared fix. |
| Published Files | Page heading/back action can wrap; grid minimum widths respect the container. |
| Duplicate queue and duplicate groups | Queue heading/actions now wrap. Duplicate-card grids can shrink within nested panels. |
| Notifications | Notification title/status can wrap, and long message text can break. |
| Admin Control Center | Existing responsive columns and table. Long system-health details can wrap. |
| Distribution, Recent, Downloads, Audit Logs | Existing responsive tables and Bootstrap layouts found. Distribution picker has mobile breakpoints. Browser checks remain pending. |
| API landing page | Included padding in the panel width with border-box sizing; reduced padding on smaller screens and allowed long content to wrap. |

## Automated validation

- PHP syntax check: all 30 view templates passed.
- Existing view/layout tests: 16 tests, 175 assertions passed.
- These checks do not measure rendered overflow or prove that every screen is responsive.
- The wider working-tree whitespace check reports existing CRLF line endings in Login and the duplicate queue; line endings were preserved.

## Browser validation still needed

Check each page at 320px, 375px, 768px, 1024px, and 1440px, including a short landscape viewport. Use populated and empty results, long folder/file names, many pagination pages, and each applicable role. Verify that the page itself does not scroll horizontally, intentional table/chart scrolling works, folder menus remain accessible, and every dialog's controls and close/save buttons are reachable. Check navigation between pages as well as direct page loads.
