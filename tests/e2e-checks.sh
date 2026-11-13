#!/bin/bash
# E2E checks of the ELOG posting rules (append-only entries, attachments, numbering).
# It writes test entries, so run it only against a throwaway instance: use tests/run-e2e.sh.
# usage: e2e-checks.sh <base url of doku.php> <dokuwiki dir of that instance> <report file>
# Needs: accounts e2e_member (group member), e2e_pending (group pending), e2e_admin (group admin)
#        with the password in $E2E_PW; a public logbook $E2E_PUB where @member may post, with
#        entry number $E2E_ENTRYNUM; a logbook $E2E_PRIV that only administrators can read;
#        $conf['userewrite'] = 0.
B=$1; E=$2; REPORT=$3; PW=${E2E_PW:?}
AJ="${B%/doku.php}/lib/exe/ajax.php"
W=$(mktemp -d); pass=0; fail=0; : > "$REPORT"
PUB=${E2E_PUB:-demo}; PRIV=${E2E_PRIV:-private}; NUM=${E2E_ENTRYNUM:-2}
say() { echo "$*" | tee -a "$REPORT"; }
ok()  { if [ "$2" = "$3" ]; then pass=$((pass+1)); say "[PASS] $1"; else fail=$((fail+1)); say "[FAIL] $1 (expected '$3', got '$2')"; fi; }
npages() { ls "$E/data/pages/$1" | wc -l | tr -d ' '; }
nmedia() { find "$E/data/media/$1" -type f 2>/dev/null | wc -l | tr -d ' '; }
login() { rm -f "$W/$1.jar"; curl -s -o /dev/null -c "$W/$1.jar" -d "do=login&id=start&u=$1&p=$PW" "$B"; curl -s -b "$W/$1.jar" "$B?id=start" | grep -c "Logged in as: <b>" ; }
tok() { curl -s -b "$W/$1.jar" "$B?id=start" | grep -oE 'sectok=[a-f0-9]{32}' | head -1 | cut -d= -f2; }
printf 'GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;' > "$W/p.gif"; printf 'root' > "$W/f.root"
ENTRY=$PUB:$NUM; ENTRYFILE="$E/data/pages/$PUB/$NUM.txt"; sum() { sha256sum "$ENTRYFILE" | cut -c1-16; }

say "== ELOG guard E2E  $(date -Is)  $B  $(cat "$E/VERSION")"
say "-- anonymous"
n=$(npages $PUB); curl -s -o /dev/null -F do=newlog_save -F id=$PUB:start -F log_subject=A1 -F log_content=x "$B"; ok "A1 anonymous cannot post in a public logbook" "$(npages $PUB)" "$n"
n=$(npages $PRIV); m=$(nmedia $PRIV); curl -s -o /dev/null -F do=newlog_save -F id=$PRIV:start -F log_subject=A2 -F log_content=x -F "log_images[]=@$W/p.gif;filename=a.php" "$B"; ok "A2 anonymous cannot post in a private logbook" "$(npages $PRIV)" "$n"; ok "A2b no attachment stored" "$(nmedia $PRIV)" "$m"
m=$(nmedia $PUB); r=$(curl -s -F do=upload_root_temp -F ns=$PUB -F "root_file=@$W/f.root;filename=a.root" "$B" | grep -c '"success":true'); ok "A3 anonymous ROOT upload refused" "$r" "0"; ok "A3b no file stored" "$(nmedia $PUB)" "$m"
curl -s -o /dev/null -F do=upload_root_temp -F 'ns=../../../e2e-outside/e2ezz' -F "root_file=@$W/f.root;filename=b.root" "$B"; ok "A4 path traversal creates nothing outside media" "$(ls -d "$E/../e2e-outside/e2ezz" 2>/dev/null | wc -l | tr -d ' ')" "0"
ok "A5 private gallery hidden from anonymous" "$(curl -s "$B?id=$PRIV:start&do=photos" | grep -c 'Log #')" "0"
ok "A5b private ROOT gallery hidden from anonymous" "$(curl -s "$B?id=$PRIV:start&do=rootplots" | grep -ciE '\.root')" "0"
ok "A5c public gallery still shown" "$(curl -s "$B?id=$PUB:start&do=photos" | grep -c 'Log #' | awk '{print ($1>0)}')" "1"
ok "A6 anonymous gets no new-log form" "$(curl -s "$B?id=$PUB:start&do=newlog" | grep -c 'name="log_subject"')" "0"
ok "A7 anonymous can still read a public entry" "$(curl -s "$B?id=$ENTRY" | grep -c '<h1')" "1"

say "-- pending account (logged in, not a member)"
ok "P0 login works" "$(login e2e_pending)" "1"; T=$(tok e2e_pending)
n=$(npages $PUB); curl -s -o /dev/null -b "$W/e2e_pending.jar" -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject=P1 -F log_content=x "$B"; ok "P1 pending user cannot post" "$(npages $PUB)" "$n"
r=$(curl -s -b "$W/e2e_pending.jar" -F do=upload_root_temp -F ns=$PUB -F sectok=$T -F "root_file=@$W/f.root;filename=a.root" "$B" | grep -c '"success":true'); ok "P2 pending user ROOT upload refused" "$r" "0"

say "-- member"
ok "M0 login works" "$(login e2e_member)" "1"; T=$(tok e2e_member); J="$W/e2e_member.jar"
ok "M1 member sees the new-log form" "$(curl -s -b $J "$B?id=$PUB:start&do=newlog" | grep -c 'name="log_subject"')" "1"
n=$(npages $PUB); m=$(nmedia $PUB); loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b $J -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject='E2E member entry' -F log_type=General -F log_content='body **bold**' -F "log_images[]=@$W/p.gif;filename=pic.gif" "$B")
ok "M2 member can post a new entry" "$(npages $PUB)" "$((n+1))"; ok "M2b attachment stored with the entry" "$(nmedia $PUB)" "$((m+1))"
NEW=$(echo "$loc" | sed -E 's/.*id=//'); NEWNUM=${NEW##*:}
ok "M2c entry recorded under the member's name" "$(tail -1 "$E/data/meta/$PUB/$NEWNUM.changes" | cut -f5)" "e2e_member"
ok "M2d new entry shows its heading" "$(curl -s -b $J "$B?id=$NEW" | grep -c '<h1')" "1"
n=$(npages $PUB); curl -s -o /dev/null -b $J -F do=newlog_save -F id=$PUB:start -F sectok=bad -F log_subject=M3 -F log_content=x "$B"; ok "M3 post with a wrong security token refused" "$(npages $PUB)" "$n"
n=$(npages $PUB); curl -s -o /dev/null -b $J -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject='Re: E2E' -F log_reply_to=$NEWNUM -F log_content=reply "$B"; ok "M4 member can reply" "$(npages $PUB)" "$((n+1))"
r=$(curl -s -b $J -F do=upload_root_temp -F ns=$PUB -F sectok=$T -F "root_file=@$W/f.root;filename=m.root" "$B" | grep -c '"success":true'); ok "M5 member ROOT upload works" "$r" "1"
r=$(curl -s -b $J -F do=upload_root_temp -F ns=$PUB -F "root_file=@$W/f.root;filename=m2.root" "$B" | grep -c '"success":true'); ok "M5b ROOT upload without token refused" "$r" "0"
curl -s -o /dev/null -b $J -F do=upload_root_temp -F 'ns=../../../e2e-outside/e2ezz' -F sectok=$T -F "root_file=@$W/f.root;filename=t.root" "$B"; ok "M5c member path traversal creates nothing outside media" "$(ls -d "$E/../e2e-outside/e2ezz" 2>/dev/null | wc -l | tr -d ' ')" "0"
ok "M6 member sees no section edit button" "$(curl -s -b $J "$B?id=$ENTRY" | grep -ciE 'btn_secedit|class="secedit')" "0"
ok "M7 member gets no edit form" "$(curl -s -b $J "$B?id=$ENTRY&do=edit" | grep -ciE 'name="log_content"|name="wikitext"')" "0"
s=$(sum); curl -s -o /dev/null -b $J -F do=edit_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=$T -F log_subject=HACK -F log_content=hack "$B"; ok "M8 member cannot change an entry through the ELOG edit form" "$(sum)" "$s"
curl -s -o /dev/null -b $J -d "do=save&id=$ENTRY&sectok=$T&rev=0&date=$(stat -c %Y "$ENTRYFILE")&prefix=.&suffix=&changecheck=x&summary=hack" --data-urlencode "wikitext=hacked by core save" "$B"; ok "M9 member cannot change an entry through the DokuWiki editor" "$(sum)" "$s"
curl -s -o /dev/null -b $J -d "do=save&id=$ENTRY&sectok=$T&rev=0&date=$(stat -c %Y "$ENTRYFILE")&prefix=.&suffix=&summary=del&wikitext=" "$B"; ok "M9b member cannot delete an entry" "$(ls "$ENTRYFILE" | wc -l | tr -d ' ')" "1"
n=$(npages $PUB); curl -s -o /dev/null -b $J -d "do=save&id=$PUB:e2efreepage&sectok=$T&rev=0&date=0&prefix=.&suffix=&summary=new" --data-urlencode "wikitext=free page" "$B"; ok "M10 member cannot create arbitrary pages with the DokuWiki editor" "$(npages $PUB)" "$n"
n=$(npages $PRIV); curl -s -o /dev/null -b $J -F do=newlog_save -F id=$PRIV:start -F sectok=$T -F log_subject=M11 -F log_content=x "$B"; ok "M11 member cannot post in a logbook without rights" "$(npages $PRIV)" "$n"
m=$(nmedia $PUB); curl -s -o "$W/mu.json" -b $J -H 'Content-Type: application/octet-stream' --data-binary "@$W/p.gif" "$AJ?call=mediaupload&ns=$PUB&qqfile=e2e_mm_$(date +%s).gif&sectok=$T"; ok "M12 member can still upload through the media manager" "$(nmedia $PUB)" "$((m+1))"

say "-- attachments, ROOT temp files, numbering (member)"
s=$(sum); m=$(nmedia $PUB); a0=$(ls "$E/data/media/$PUB" | grep -c "^${NUM}_.*_added\.gif$"); curl -s -o /dev/null -b $J -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=$T -F "log_images[]=@$W/p.gif;filename=added.gif" "$B"
ok "M13 member can add an attachment to an existing entry" "$(nmedia $PUB)" "$((m+1))"; ok "M13b entry text unchanged by adding an attachment" "$(sum)" "$s"
ok "M13c added file belongs to that entry" "$(ls "$E/data/media/$PUB" | grep -c "^${NUM}_.*_added\.gif$")" "$((a0+1))"
ok "M13f the upload is recorded with the member's name" "$(cat "$E/data/media_meta/$PUB"/${NUM}_*_added.gif.changes 2>/dev/null | tail -1 | cut -f5)" "e2e_member"
ok "M13d member sees the Add Attachment button" "$(curl -s -b $J "$B?id=$ENTRY" | grep -c 'do=attach')" "1"
ok "M13e member gets the attachment form" "$(curl -s -b $J "$B?id=$ENTRY&do=attach" | grep -c 'name="do" value="attach_save"')" "1"
m=$(nmedia $PUB); curl -s -o /dev/null -b $J -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=$T -F "log_images[]=@$W/p.gif;filename=evil.php" "$B"; ok "M14 unknown file type is rejected" "$(nmedia $PUB)" "$m"
m=$(nmedia $PUB); curl -s -o /dev/null -b $J -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=$T -F "log_images[]=@$W/p.gif;filename=doc.pdf" "$B"; ok "M14c non-image file type (pdf) is rejected" "$(nmedia $PUB)" "$m"
m=$(nmedia $PUB); curl -s -o /dev/null -b $J -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=$T -F "log_images[]=@$W/p.gif;filename=phone.heic" "$B"; ok "M14d HEIC image is accepted" "$(nmedia $PUB)" "$((m+1))"
m=$(nmedia $PUB); curl -s -o /dev/null -b $J -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=bad -F "log_images[]=@$W/p.gif;filename=x.gif" "$B"; ok "M14b attachment with a wrong security token refused" "$(nmedia $PUB)" "$m"
m=$(nmedia $PUB); curl -s -o /dev/null -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F "log_images[]=@$W/p.gif;filename=x.gif" "$B"; ok "A8 anonymous cannot add an attachment" "$(nmedia $PUB)" "$m"
m=$(nmedia $PUB); TP=$(tok e2e_pending); curl -s -o /dev/null -b "$W/e2e_pending.jar" -F do=attach_save -F id=$ENTRY -F real_id=$ENTRY -F sectok=$TP -F "log_images[]=@$W/p.gif;filename=x.gif" "$B"; ok "P3 pending user cannot add an attachment" "$(nmedia $PUB)" "$m"
ok "A9 anonymous sees no Add Attachment button" "$(curl -s "$B?id=$ENTRY" | grep -c 'do=attach')" "0"

login e2e_admin >/dev/null; TA=$(tok e2e_admin)
other=$(curl -s -b "$W/e2e_admin.jar" -F do=upload_root_temp -F ns=$PUB -F sectok=$TA -F "root_file=@$W/f.root;filename=other_user.root" "$B" | grep -oE '"temp_name":"[^"]+"' | cut -d'"' -f4)
mine=$(curl -s -b $J -F do=upload_root_temp -F ns=$PUB -F sectok=$T -F "root_file=@$W/f.root;filename=mine.root" "$B" | grep -oE '"temp_name":"[^"]+"' | cut -d'"' -f4)
unlisted=$(curl -s -b $J -F do=upload_root_temp -F ns=$PUB -F sectok=$T -F "root_file=@$W/f.root;filename=abandoned.root" "$B" | grep -oE '"temp_name":"[^"]+"' | cut -d'"' -f4)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b $J -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject='E2E root entry' -F log_content=x -F "root_temp_files=$mine,$other" "$B"); RN=${loc##*:}
ok "M16 own listed ROOT file is attached to the new entry" "$(ls "$E/data/media/$PUB" | grep -c "^${RN}_.*_mine\.root$")" "1"
ok "M16b another user's temporary ROOT file is not taken" "$(ls "$E/data/media/$PUB/$other" 2>/dev/null | wc -l | tr -d ' ')" "1"
ok "M16c own temporary file that the form did not list is not taken" "$(ls "$E/data/media/$PUB/$unlisted" 2>/dev/null | wc -l | tr -d ' ')" "1"
ok "M16d nothing of the other user is attached to the entry" "$(ls "$E/data/media/$PUB" | grep -c "^${RN}_.*other_user")" "0"

n=$(npages $PUB); RUN=$(date +%s); for i in 1 2 3 4 5 6 7 8; do curl -s -o /dev/null -b $J -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject="E2E parallel $RUN-$i" -F log_content="parallel $i" "$B" & done; wait
ok "M15 eight simultaneous posts create eight entries" "$(npages $PUB)" "$((n+8))"
ok "M15b each simultaneous post kept its own text" "$(grep -l "E2E parallel $RUN-" "$E/data/pages/$PUB"/*.txt | wc -l | tr -d ' ')" "8"
ok "M15c no entry was overwritten (one revision each)" "$(for f in $(grep -l "E2E parallel $RUN-" "$E/data/pages/$PUB"/*.txt); do b=$(basename $f .txt); wc -l < "$E/data/meta/$PUB/$b.changes"; done | sort -u | tr -d ' \n')" "1"

say "-- help page"
ok "H1 anonymous can read the Help page" "$(curl -s "$B?id=help:start" | grep -c '<h1')" "1"
ok "H2 Help page shows no permission error" "$(curl -s "$B?id=help:start" | grep -ciE 'permission denied')" "0"

say "-- administrator"
ok "D0 login works" "$(login e2e_admin)" "1"; T=$(tok e2e_admin); J="$W/e2e_admin.jar"
ok "D1 admin sees section edit buttons" "$(curl -s -b $J "$B?id=$ENTRY" | grep -ciE 'btn_secedit|class="secedit' | awk '{print ($1>0)}')" "1"
ok "D2 admin gets the edit form" "$(curl -s -b $J "$B?id=$NEW&do=edit" | grep -c 'name="log_content"')" "1"
NEWFILE="$E/data/pages/$PUB/$NEWNUM.txt"; curl -s -o /dev/null -b $J -F do=edit_save -F id=$NEW -F real_id=$NEW -F sectok=$T -F log_subject='E2E edited by admin' -F log_type=General -F log_content='edited' "$B"; ok "D3 admin can edit an entry" "$(grep -c 'E2E edited by admin' "$NEWFILE")" "2"
curl -s -o /dev/null -b $J "$B?id=$NEW&do=delete_page&sectok=$T"; ok "D4 admin can delete an entry" "$(ls "$NEWFILE" 2>/dev/null | wc -l | tr -d ' ')" "0"

say "-- numbers of deleted entries"
J="$W/e2e_member.jar"; login e2e_member >/dev/null; T=$(tok e2e_member)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b $J -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject='E2E to be deleted' -F log_content=x -F "log_images[]=@$W/p.gif;filename=old.gif" "$B"); DEL=${loc##*=}; DELNUM=${DEL##*:}
JA="$W/e2e_admin.jar"; login e2e_admin >/dev/null; TA=$(tok e2e_admin); curl -s -o /dev/null -b $JA "$B?id=$DEL&do=delete_page&sectok=$TA"
ok "N0 the newest entry was deleted by the admin" "$(ls "$E/data/pages/$PUB/$DELNUM.txt" 2>/dev/null | wc -l | tr -d ' ')" "0"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b $J -F do=newlog_save -F id=$PUB:start -F sectok=$T -F log_subject='E2E after deletion' -F log_content=x "$B"); NXT=${loc##*=}; NXTNUM=${NXT##*:}
ok "N1 the number of a deleted entry is not reused" "$([ "$NXTNUM" -gt "$DELNUM" ] && echo higher || echo "reused-or-lower:$NXTNUM")" "higher"
ok "N2 the new entry does not show the deleted entry's attachment" "$(curl -s -b $J "$B?id=$NXT" | grep -c 'old\.gif')" "0"

say "== Summary: $pass passed, $fail failed"
rm -rf "$W"; [ "$fail" = 0 ]
