# GreyhoundHub post publishing

New photos and videos sent with **Post** in file management appear in **Latest posts** on the GreyhoundHub home page for `admin` and `super_admin` accounts. The public feed stays separate from this admin workspace. There is no Pending label.

Server Library's confirmation posts up to 20 selected files as an untitled draft;
camera filenames are not treated as user-entered post titles. Copies preserve
the originals. The dialog shows **Posting…** during transfer, and the request
allows up to five minutes for copying without holding the user's session lock.
Admins add the title in the mobile editor before publishing.

Posting copies up to two originals concurrently using PHP CLI workers, with a
180-second batch deadline. Hosts without process support use sequential copies.
Destination names are reserved before copying, sizes are checked afterward,
and failed batches remove their local copies after stopping the workers.
The log records batch count, bytes, and copy duration for speed comparisons.

Photos selected in one Post action form one photo post. Posting a folder includes its available photos and videos from its subfolders. Videos form individual posts. A photo post starts with a collage of the first five photos, or all photos if fewer than five are available.

Click a Latest posts card to open its editor. Admins can change the title and description, pin the post, and choose one thumbnail image or the photos and order for a collage. **Save edits** keeps a new post private. **Publish** saves the edits and makes the post available to regular users. Publishing removes the post from **Latest posts**, which lists only unpublished posts. On public post cards, admins can use the three-dot menu to **Edit post**, **Unpublish post**, and **Pin post / Unpin post**. Unpublishing returns the post to Latest posts and hides its media from public feeds, assets, and interactions. Published posts can be reopened and updated with **Publish changes**. Pinned posts appear first in the home feed.

The server enforces admin authorization for listing, editing, publishing, and retrieving private thumbnail data. Public mobile feeds, search, channels, media assets, downloads, history, and interactions require app publication. Thumbnail selections must belong to the post. Version checks reject stale edits from another open editor.

The additive migration `2026-09-12-000001_AddAppPublishing` creates `mobile_posts` and adds app publication fields to `files`. It preserves media already public before installation. New uploads start without an app publication timestamp. Posting existing files again makes those files private until the new post is published. File-management access and department deliveries retain their separate permissions.

Deployment: run `php spark migrate` against the target database before serving the updated API and app. This migration has been applied to the local database for this workspace. Rollback deliberately requires a migration that preserves the visibility rules rather than dropping the fields and exposing private files.

Checks: `php vendor/bin/phpunit tests/unit/AppPublishingTest.php tests/unit/MobileThumbnailResponseTest.php --no-coverage` and, inside `greyhound-app-2025-main`, `node --test tests/search-functional.cjs tests/media-functional.cjs`.

## Featured slideshow

On desktop and mobile Home, admins can choose **Edit featured**, search published photos, and select up to 20 individual images (including images inside photo posts). Selection numbers define slideshow order. **Save featured** persists the selection for all viewers; Cancel leaves it unchanged. Saving an empty selection hides the slideshow. Unpublished/deleted photos are excluded automatically. Before the first saved selection, the slideshow uses five available public photos.

Migrations `2026-09-15-000001_AddMobileFeatured` and `2026-09-15-000002_AddLegacyAppPosts` store the slideshow configuration and give older public uploads post records so they also support the admin menu. The latter preserves their visibility and existing publication dates. Both have been applied to the local workspace database. Run `php spark migrate` when deploying this update elsewhere.
