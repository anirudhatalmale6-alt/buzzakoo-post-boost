#!/bin/bash
# Regression suite for Buzzakoo Post Boost.
# Every case runs in its own PHP process, so settings are read fresh — the same way
# a real web request reads them.
cd /var/lib/freelancer/projects/40574648/wp || exit 1
WPE="php /tmp/wp-cli.phar --allow-root eval"
PASS=0; FAIL=0

check() { # check <label> <output> <needle>
  if echo "$2" | grep -q "$3"; then echo "  PASS  $1"; PASS=$((PASS+1));
  else echo "  FAIL  $1"; echo "        got: $(echo "$2" | tail -3)"; FAIL=$((FAIL+1)); fi
}

reset() {
  $WPE 'global $wpdb; $wpdb->query("DELETE FROM wp_bzk_boosts"); $wpdb->query("DELETE FROM wp_bzk_boost_log"); wp_cache_flush();' >/dev/null 2>&1
}
setopt() { $WPE "\$s=get_option('bzk_boost_settings'); $1 update_option('bzk_boost_settings',\$s);" >/dev/null 2>&1; }

defaults() {
  setopt '$s["enable_activity"]=1; $s["enable_posts"]=1; $s["enable_bbpress"]=1;
          $s["post_types"]=array("post"); $s["allow_roles"]=array("administrator","editor","author","contributor","subscriber");
          $s["allow_author"]=1; $s["allow_guests"]=0; $s["boost_duration_hours"]=24;
          $s["cooldown_minutes"]=0; $s["user_cooldown_minutes"]=0; $s["max_boosts_per_item"]=0;
          $s["max_boosted_items"]=0; $s["above_sticky"]=0;'
}

echo "=============================================="
echo " Buzzakoo Post Boost — regression suite"
echo "=============================================="

echo
echo "[1] BuddyPress activity stream"
reset; defaults
OUT=$($WPE 'wp_set_current_user(1);
  $f=bp_activity_get(array("per_page"=>10)); $ids=wp_list_pluck($f["activities"],"id"); $oldest=end($ids);
  bzk_boost("activity",$oldest);
  $f2=bp_activity_get(array("per_page"=>10)); $after=wp_list_pluck($f2["activities"],"id");
  echo ($after[0]==$oldest) ? "BOOST_TOP_OK\n":"BAD\n";
  $rest=array_slice($after,1); $sorted=$rest; rsort($sorted);
  echo ($rest===array_values(array_filter($ids,function($i)use($oldest){return $i!=$oldest;}))) ? "ORDER_PRESERVED_OK\n":"ORDER_BROKEN\n";
  global $wpdb; echo $wpdb->last_error ? "SQLERR: ".$wpdb->last_error."\n" : "NO_SQL_ERROR\n";' 2>&1)
check "boosted activity renders first" "$OUT" "BOOST_TOP_OK"
check "remaining activities keep date order" "$OUT" "ORDER_PRESERVED_OK"
check "no SQL error (SELECT DISTINCT safe)" "$OUT" "NO_SQL_ERROR"

echo
echo "[2] Boost expiry restores natural order"
OUT=$($WPE 'global $wpdb; $wpdb->query("UPDATE wp_bzk_boosts SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE");
  bp_core_reset_incrementor("bp_activity"); bp_core_reset_incrementor("bp_activity_with_last_activity"); wp_cache_flush();
  $f=bp_activity_get(array("per_page"=>10));
  // Natural order = strictly newest-first by date_recorded. Assert the dates never increase.
  $dates=wp_list_pluck($f["activities"],"date_recorded");
  $ok=true; for($i=1;$i<count($dates);$i++){ if(strtotime($dates[$i]) > strtotime($dates[$i-1])) $ok=false; }
  echo $ok ? "NATURAL_ORDER_RESTORED\n" : "STILL_BOOSTED\n";' 2>&1)
check "expired boost falls back to date order" "$OUT" "NATURAL_ORDER_RESTORED"

echo
echo "[3] WordPress posts + archives"
reset; defaults
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  bzk_boost("post",$ids[6]);
  $q=new WP_Query(array("post_type"=>"post","posts_per_page"=>10));
  echo ($q->posts[0]->ID==$ids[6]) ? "BLOG_TOP_OK\n":"BLOG_BAD\n";
  $cat=get_option("bzk_test_cat");
  $q2=new WP_Query(array("post_type"=>"post","posts_per_page"=>10,"cat"=>$cat));
  echo ($q2->posts[0]->ID==$ids[6]) ? "ARCHIVE_TOP_OK\n":"ARCHIVE_BAD\n";
  $q3=new WP_Query(array("post_type"=>"post","posts_per_page"=>10,"s"=>"Post"));
  echo (count($q3->posts) && $q3->posts[0]->ID==$ids[6]) ? "SEARCH_TOP_OK\n":"SEARCH_BAD\n";
  global $wpdb; echo $wpdb->last_error ? "SQLERR: ".$wpdb->last_error."\n":"NO_SQL_ERROR\n";' 2>&1)
check "boosted post first on blog loop" "$OUT" "BLOG_TOP_OK"
check "boosted post first on category archive" "$OUT" "ARCHIVE_TOP_OK"
check "boosted post first in search results" "$OUT" "SEARCH_TOP_OK"
check "no SQL error on posts queries" "$OUT" "NO_SQL_ERROR"

echo
echo "[4] Pagination integrity (boost must not duplicate/drop posts)"
OUT=$($WPE '$ids=get_option("bzk_test_ids");
  $p1=new WP_Query(array("post_type"=>"post","posts_per_page"=>3,"paged"=>1));
  $p2=new WP_Query(array("post_type"=>"post","posts_per_page"=>3,"paged"=>2));
  $a=wp_list_pluck($p1->posts,"ID"); $b=wp_list_pluck($p2->posts,"ID");
  $all=array_merge($a,$b);
  echo ($a[0]==$ids[6]) ? "PAGE1_BOOSTED_FIRST\n":"PAGE1_BAD\n";
  echo (count($all)===count(array_unique($all))) ? "NO_DUPES_ACROSS_PAGES\n":"DUPLICATE_POSTS\n";' 2>&1)
check "page 1 starts with boosted post" "$OUT" "PAGE1_BOOSTED_FIRST"
check "no duplicates across paginated pages" "$OUT" "NO_DUPES_ACROSS_PAGES"

echo
echo "[5] Sticky posts"
reset; defaults
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  stick_post($ids[1]); bzk_boost("post",$ids[6]);
  $q=new WP_Query(array("post_type"=>"post","posts_per_page"=>10));
  echo ($q->posts[0]->ID==$ids[1]) ? "STICKY_WINS_BY_DEFAULT\n":"STICKY_NOT_FIRST\n";' 2>&1)
check "sticky still outranks boost by default" "$OUT" "STICKY_WINS_BY_DEFAULT"
setopt '$s["above_sticky"]=1;'
OUT=$($WPE '$ids=get_option("bzk_test_ids"); wp_cache_flush();
  $q=new WP_Query(array("post_type"=>"post","posts_per_page"=>10));
  echo ($q->posts[0]->ID==$ids[6]) ? "BOOST_BEATS_STICKY\n":"STICKY_STILL_FIRST\n";' 2>&1)
check "above_sticky=1 makes boost outrank sticky" "$OUT" "BOOST_BEATS_STICKY"
$WPE '$ids=get_option("bzk_test_ids"); unstick_post($ids[1]);' >/dev/null 2>&1

echo
echo "[6] Rules: cooldown, caps, roles"
reset; defaults; setopt '$s["cooldown_minutes"]=60;'
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  $a=bzk_boost("post",$ids[3]); echo is_wp_error($a)?"FIRST_BLOCKED\n":"FIRST_OK\n";' 2>&1)
check "first boost allowed" "$OUT" "FIRST_OK"
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  $b=bzk_boost("post",$ids[3]); echo (is_wp_error($b) && $b->get_error_code()==="bzk_cooldown")?"COOLDOWN_ENFORCED\n":"COOLDOWN_NOT_ENFORCED\n";' 2>&1)
check "item cooldown blocks re-boost" "$OUT" "COOLDOWN_ENFORCED"

reset; defaults; setopt '$s["max_boosts_per_item"]=2;'
$WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids"); bzk_boost("post",$ids[2]);' >/dev/null 2>&1
$WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids"); bzk_boost("post",$ids[2]);' >/dev/null 2>&1
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  $r=bzk_boost("post",$ids[2]); echo (is_wp_error($r) && $r->get_error_code()==="bzk_max_reached")?"CAP_ENFORCED\n":"CAP_NOT_ENFORCED\n";' 2>&1)
check "lifetime cap per item enforced" "$OUT" "CAP_ENFORCED"

reset; defaults; setopt '$s["allow_roles"]=array("administrator"); $s["allow_author"]=0;'
OUT=$($WPE '$u=get_user_by("login","subby"); wp_set_current_user($u->ID); $ids=get_option("bzk_test_ids");
  $r=bzk_boost("post",$ids[5]); echo (is_wp_error($r) && $r->get_error_code()==="bzk_forbidden")?"ROLE_ENFORCED\n":"ROLE_NOT_ENFORCED\n";' 2>&1)
check "disallowed role is blocked" "$OUT" "ROLE_ENFORCED"

OUT=$($WPE 'wp_set_current_user(0); $ids=get_option("bzk_test_ids");
  $r=bzk_boost("post",$ids[5]); echo is_wp_error($r)?"GUEST_BLOCKED\n":"GUEST_ALLOWED\n";' 2>&1)
check "logged-out guest blocked when guests off" "$OUT" "GUEST_BLOCKED"

echo
echo "[7] max_boosted_items = 1 (single top slot)"
reset; defaults; setopt '$s["max_boosted_items"]=1;'
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  bzk_boost("post",$ids[6]); bzk_boost("post",$ids[5]);
  global $wpdb; $r=$wpdb->get_results("SELECT object_id FROM wp_bzk_boosts");
  echo (count($r)==1 && (int)$r[0]->object_id===(int)$ids[5]) ? "SINGLE_SLOT_OK\n":"SINGLE_SLOT_BAD\n";' 2>&1)
check "only newest boost stays pinned" "$OUT" "SINGLE_SLOT_OK"

echo
echo "[8] Un-boost"
reset; defaults
OUT=$($WPE 'wp_set_current_user(1); $ids=get_option("bzk_test_ids");
  bzk_boost("post",$ids[6]); BZK_Store::unboost("post",$ids[6]); wp_cache_flush();
  $q=new WP_Query(array("post_type"=>"post","posts_per_page"=>10));
  echo ($q->posts[0]->ID!=$ids[6]) ? "UNBOOST_OK\n":"STILL_PINNED\n";' 2>&1)
check "un-boost returns post to normal position" "$OUT" "UNBOOST_OK"

echo
echo "=============================================="
echo " PASSED: $PASS    FAILED: $FAIL"
echo "=============================================="
[ "$FAIL" -eq 0 ]
