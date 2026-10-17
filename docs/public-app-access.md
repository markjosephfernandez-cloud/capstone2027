# Public browsing and admin sign-in

The Expo app in `greyhound-app-2025-main` opens Home without requiring a login.
Visitors can browse published photos, videos, featured photos, search results,
and public comments. Saved items and Watch Later are stored on the visitor's
device, separately from signed-in account libraries.

## Editing featured content

1. Choose **Admin sign in** in the desktop header/sidebar, the mobile Home
   sign-in button/menu, or the Library screen.
2. Sign in with an existing `admin` or `super_admin` account.
3. On Home, choose **Edit featured** to select and order published photos.
   Existing post editing and publishing controls are also available to admins.
4. Choose **Log Out** from the Home account/navigation menu to return to public
   browsing. The app clears the token and resets navigation.

**Continue browsing** leaves the login screen without requiring credentials.
Expired saved sessions fall back to public browsing. Guest screens do not issue
protected profile, engagement, library, or watch-history requests. Reactions and
comment submission remain account features; admin writes still require the
server's admin role checks. Draft and withdrawn media remain private.

This changes access within the app. It does not deploy the app or API to a public
internet host; the configured server address still determines network access.

## Verification

- App tests cover guest startup, desktop/mobile admin sign-in links, local
  library isolation, expired sessions, logout, and admin editing controls.
- Backend publishing tests cover public visibility and admin permissions.
- The Expo web export builds successfully. Browser visual testing was unavailable
  in the implementation session because no browser was connected.
