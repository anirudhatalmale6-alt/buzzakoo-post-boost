# Buzzakoo Post Boost

Lets users **boost** (bump) an item so it jumps back to the top of the public feeds —
like the bump function on a classic forum.

Built for **Buzzakoo.com** (WordPress + SocialV/Iqonic + BuddyPress + bbPress).

---

## The important thing to know first

Buzzakoo's main feed — the one on the homepage and on `/activity/` — is the **BuddyPress
activity stream**, not the WordPress blog loop.

BuddyPress does **not** build that feed with `WP_Query`. It has its own table
(`wp_bp_activity`) and its own hand-written SQL. A boost plugin that only reorders
WordPress posts will install cleanly, look correct, and **do nothing at all** to the
main feed.

This plugin therefore hooks all three surfaces the site actually has:

| Surface | Where it shows | How it is reordered |
|---|---|---|
| **BuddyPress activity** | Homepage, `/activity/` — *the main feed* | Rewrites BP's activity SQL |
| **WordPress posts** | `/blog/`, category / tag / search / author archives | `posts_clauses` filter |
| **bbPress topics** | `/forums/` topic lists | `posts_clauses` (topics are a post type) |

Each integration switches itself off cleanly if its host plugin isn't active.

---

## How it works

### Storage

Two tables. Boost state is in the database, so it survives page refreshes, cache
flushes and server restarts.

* **`wp_bzk_boosts`** — current state, one row per boosted item.
  Primary key `(object_type, object_id)`, so an item is either boosted or it isn't;
  it can never be double-inserted.
* **`wp_bzk_boost_log`** — append-only history of every boost event.
  Drives cooldowns and the per-item lifetime cap.

`boosted_at` is `datetime(6)` — **microseconds, not seconds**. On a busy feed two
people can easily boost inside the same second; at one-second resolution those boosts
tie and "most recently boosted wins" quietly stops being true.

All timestamps are UTC and are compared against MySQL's `UTC_TIMESTAMP()`, so boost
expiry doesn't drift with the site's timezone setting.

### Ordering

Ordering happens **in SQL**, not by shuffling the array of results afterwards. That's
what keeps pagination honest: a boosted item on page 1 is genuinely first in the result
set, rather than being moved to the top of a page that was already fetched (which would
duplicate items across pages).

The boost ranking is *prepended* to whatever ordering the query already had, so the
site's existing logic — date, sticky, `menu_order`, bbPress's last-active-time meta —
still applies underneath. When a boost expires the item silently drops back to its
natural position; nothing needs to be cleaned up for ordering to be correct.

### Two subtleties that will bite anyone who modifies this

**1. `SELECT DISTINCT` + `ORDER BY` on a joined column.**
BuddyPress's activity query and WordPress's taxonomy archives both use `SELECT DISTINCT`.
MySQL refuses to `ORDER BY` an expression that isn't in the select list when `DISTINCT`
is used (error 3065). So the boost column is added to the `SELECT` list and the sort
runs on that. This is why the ordering is a plain `bzk.boosted_at DESC` and **not**
`(bzk.boosted_at IS NOT NULL) DESC, ...` — the latter is an expression and would fatal
on exactly the category pages this feature is for. (`DESC` already sorts `NULL`s last
in MySQL, which is precisely the behaviour we want.)

**2. Cache invalidation is not optional.**
Both WordPress and BuddyPress cache the *list of IDs* a query returns:

* WordPress keys cached `WP_Query` results on the query args plus the `posts` group's
  `last_changed` stamp.
* BuddyPress keys cached activity IDs on the **SQL string** itself.

A boost writes only to our own table — it never touches `wp_posts` or `wp_bp_activity`,
and it doesn't change the SQL text. So neither cache would notice, and the feed would
keep serving the **old order**. This is invisible on a dev box with no object cache and
extremely visible on a live site with Redis.

`BZK_Cache::purge()` therefore bumps `wp_cache_set_last_changed('posts')` and resets
BuddyPress's activity incrementors on every boost. If you change how boosts are stored,
keep that call.

### Caching / the button

The button is rendered server-side in a **neutral, disabled state**, and its real state
(boosted? on cooldown? how many boosts?) is fetched over REST on page load.

That's deliberate: it means a full-page cache can never serve a stale "Boosted" label to
the wrong visitor. It also means the REST nonce baked into a cached page (which goes
stale) is never used for a write — `/state` returns a **fresh nonce**, and the button
writes with that one.

---

## Settings

**WP Admin → Post Boost**

* **Where boosting applies** — activity stream / WP posts / bbPress topics, and which post types.
* **Who may boost** — by role; optionally "the item's own author always may"; optionally logged-out guests (rate-limited by IP).
* **Boost lasts for** — hours; `0` = never expires (stays until something else is boosted above it).
* **Cooldown per item** — minimum gap between two boosts of the same item.
* **Cooldown per user** — minimum gap between any two boosts by the same person.
* **Maximum boosts per item** — lifetime cap; `0` = unlimited.
* **Maximum boosted items at once** — keeps only the newest N pinned. Set to `1` for a single "top slot".
* **Button label / extra CSS classes / position**, show boost count.
* **Sticky posts** — whether a boost outranks a WordPress sticky post (off by default).
* **Caching** — flush page caches after a boost.

**WP Admin → Post Boost → Currently boosted** lists everything pinned right now, with a
one-click **Un-boost**. Posts and topics also get Boost / Un-boost row actions in the
normal list tables.

---

## Placing the button

It appears automatically in the BuddyPress activity action bar (next to Comment /
Favourite) and in post content.

To place it yourself:

```php
<?php if ( function_exists( 'bzk_boost_button' ) ) { bzk_boost_button( 'post', get_the_ID() ); } ?>
```

or the shortcode:

```
[buzzakoo_boost]
[buzzakoo_boost type="activity" id="3229"]
```

Set **Button position on posts → "Do not add automatically"** if you're placing it manually.

To make it match the theme's buttons, put the theme's own button classes into
**Extra CSS classes** (e.g. SocialV's `btn btn-primary`). The button inherits font and
colour from its surroundings by default rather than imposing its own.

---

## For developers

```php
// Boost programmatically.
bzk_boost( 'activity', 3229 );          // returns true or WP_Error

// React to a boost.
add_action( 'bzk_boosted', function ( $type, $id, $user_id ) { /* … */ }, 10, 3 );

// Have the last word on who may boost.
add_filter( 'bzk_can_boost', function ( $allowed, $type, $id, $user_id ) {
    return $allowed;
}, 10, 4 );

// Exclude a specific query from boost ordering.
add_filter( 'bzk_apply_to_activity_query', '__return_false' );
add_filter( 'bzk_apply_to_post_query', '__return_false' );

// Restyle the button completely.
add_filter( 'bzk_boost_button_html', function ( $html, $type, $id ) { return $html; }, 10, 3 );
```

REST API:

```
GET  /wp-json/buzzakoo-boost/v1/state?items=activity:12,post:34
POST /wp-json/buzzakoo-boost/v1/boost     { "type": "activity", "id": 12 }
POST /wp-json/buzzakoo-boost/v1/unboost   { "type": "activity", "id": 12 }   (admins)
```

---

## Install

1. Upload `buzzakoo-boost` to `wp-content/plugins/` (or install the ZIP via **Plugins → Add New → Upload**).
2. Activate. Tables are created automatically.
3. Go to **Post Boost** and set the rules.

Nothing in the theme or in WordPress core is modified, so theme and core updates cannot
overwrite any of this.

**Requires:** WordPress 6.0+, PHP 7.4+. Optional: BuddyPress, bbPress.

---

## Verified against

WordPress 7.0.1, BuddyPress 14.5.0, bbPress 2.6.14, PHP 8.3, MySQL 8.0 — the same
WordPress version Buzzakoo.com runs.

19 automated checks, all passing: boosted item renders first in the activity stream /
blog loop / category archive / search; remaining items keep their date order; expiry
restores natural order; pagination has no duplicates or gaps; sticky interaction both
ways; cooldowns, lifetime caps, role rules and guest blocking; single-top-slot mode;
un-boost. Plus an end-to-end browser test that clicks the real button and confirms the
item moves from last to first **and stays there after a reload**.
