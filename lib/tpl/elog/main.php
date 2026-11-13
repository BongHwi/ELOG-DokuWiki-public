<?php
/**
 * DokuWiki elog Template
 *
 * @link     http://dokuwiki.org/template:elog
 * @author   Gemini
 */

if (!defined('DOKU_INC')) die(); /* must be run from within DokuWiki */

// Include helper functions early to handle redirects
global $ACT;
include_once(dirname(__FILE__).'/elog_functions.php');
elog_handle_actions();
elog_handle_logbook_admin();
elog_handle_delete_page();
// Output debug info immediately after handlers (Removed)

header('X-UA-Compatible: IE=edge,chrome=1');

$hasSidebar = page_findnearest($conf['sidebar']);
$showSidebar = $hasSidebar && ($ACT == 'show');
$isHomePage = ($ID == $conf['start'] && $ACT == 'show');
$isLogEntry = elog_is_log_entry($ID);

?><!DOCTYPE html>
<html lang="<?php echo $conf['lang'] ?>" dir="<?php echo $lang['direction'] ?>">
<head>
    <meta charset="utf-8" />
    <?php $fixedWidth = isset($_COOKIE['elog_fixed_width']) && $_COOKIE['elog_fixed_width'] === '1'; ?>
    <meta name="viewport" content="<?php echo $fixedWidth ? 'width=1240' : 'width=device-width, initial-scale=1'; ?>">
    <title><?php tpl_pagetitle() ?> [<?php echo strip_tags($conf['title']) ?>]</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <?php tpl_metaheaders() ?>
    <?php echo tpl_favicon(array('favicon', 'mobile')) ?>

    <?php tpl_includeFile('meta.html') ?>
    
    <!-- Google Web Fonts -->
    <?php
        // Template settings are edited via Configuration Manager. Normalize both
        // "Google Sans Flex" and "Google+Sans+Flex" into a valid CSS family name.
        $gfont_default = trim(str_replace('+', ' ', (string) tpl_getConf('googlefont')));
        $gfont_h_default = trim(str_replace('+', ' ', (string) tpl_getConf('googlefont_headings')));

        // Per-page overrides (optional)
        $gfont_start = trim(str_replace('+', ' ', (string) tpl_getConf('googlefont_start')));
        $gfont_h_start = trim(str_replace('+', ' ', (string) tpl_getConf('googlefont_headings_start')));
        $gfont_entry = trim(str_replace('+', ' ', (string) tpl_getConf('googlefont_entry')));
        $gfont_h_entry = trim(str_replace('+', ' ', (string) tpl_getConf('googlefont_headings_entry')));

        $fontsize_entry = trim((string) tpl_getConf('fontsize_entry'));

        // Effective fonts for this request
        $gfont = $gfont_default;
        $gfont_h = $gfont_h_default;
        if ($isHomePage) {
            if ($gfont_start !== '') $gfont = $gfont_start;
            if ($gfont_h_start !== '') $gfont_h = $gfont_h_start;
        } elseif ($isLogEntry) {
            if ($gfont_entry !== '') $gfont = $gfont_entry;
            if ($gfont_h_entry !== '') $gfont_h = $gfont_h_entry;
        }

        $toLoad = array();
        if ($gfont !== '') $toLoad[] = $gfont;
        if ($gfont_h !== '' && $gfont_h !== $gfont) $toLoad[] = $gfont_h;
        $toLoad = array_values(array_unique($toLoad));

        foreach ($toLoad as $family) {
            echo '<link href="https://fonts.googleapis.com/css2?family=' .
                str_replace(' ', '+', $family) .
                ':wght@300;400;500;700&display=swap" rel="stylesheet">';
        }
    ?>
    
    <!-- Custom CSS to override/extend Bootstrap -->
    <style>
        :root{
            --elog-font-body: <?php echo ($gfont !== '') ? "'".hsc($gfont)."', " : ''; ?>Verdana, sans-serif;
            --elog-font-headings: <?php echo ($gfont_h !== '') ? "'".hsc($gfont_h)."', " : ''; ?>var(--elog-font-body);
        }
        body {
            font-family: var(--elog-font-body);
            font-size: 13px; 
            background-color: #f8f9fa; 
        }
        .page-content h1, .page-content h2, .page-content h3,
        .page-content h4, .page-content h5, .page-content h6 {
            font-family: var(--elog-font-headings);
        }
        .elog-header { background-color: #E0E0E0; border-bottom: 2px solid #486090; padding-bottom: 0; }
        .nav-tabs .nav-link { color: #40A040; border-radius: 5px 5px 0 0; margin-right: 2px; background-color: #E0E0E0; border: 1px solid transparent; }
        .nav-tabs .nav-link.active { background-color: #486090; color: white !important; border-color: #486090 #486090 #486090; }
        .nav-tabs .nav-link:hover { border-color: #cccccc; }
        .elog-logo { font-size: 24px; font-weight: bold; color: #0000AA; text-decoration: none; }
        .elog-logo:hover { text-decoration: underline; }
        .elog-logo:hover { text-decoration: underline; }
        .main-shell {
            max-width: 1240px;
            margin-left: auto;
            margin-right: auto;
        }
        .page-content { background-color: white; padding: 20px; border: 1px solid #dee2e6; border-top: none; border-radius: 0 0 10px 10px; }
        .page-content-entry { font-size: <?php echo hsc($fontsize_entry ?: '15px'); ?>; }
        .page-content-entry .card { font-size: 13px; }
        /* Responsive Images */
        .page-content img { max-width: 100%; height: auto; }
        .page-content-home {
            padding: 1.75rem 2rem;
        }
        .page-content-home h1 {
            margin-top: 0;
            margin-bottom: 0.8rem;
            font-size: 1.9rem;
        }
        .page-content-home h2 {
            margin-top: 1.4rem;
            margin-bottom: 0.65rem;
            font-size: 1.15rem;
        }
        .page-content-home ul {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }
        .page-content-home ul li {
            margin-bottom: 0.35rem;
            line-height: 1.45;
        }
        .home-logbook-grid {
            margin-top: 2rem;
        }
        @media (max-width: 768px) {
            .page-content-home {
                padding: 1.15rem 1rem;
            }
            .page-content-home h1 {
                font-size: 1.55rem;
            }
        }
    </style>
</head>

<body>
    <?php tpl_includeFile('header.html') ?>

	    <div class="container-fluid p-0">
	        <!-- Top Header Row (Home, Index, User) -->
	        <div class="elog-header pt-1 px-3 bg-light border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <ul class="nav nav-tabs border-0 small">
                    <li class="nav-item">
                        <a class="nav-link py-1 <?php echo ($ID == 'start') ? 'active' : ''; ?>" href="<?php echo wl() ?>">Home</a>
                    </li>
                    <?php if(!empty($INFO['userinfo'])): ?>
                    <li class="nav-item">
                        <a class="nav-link py-1" href="<?php echo wl($ID, 'do=profile') ?>">Profile</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link py-1" href="<?php echo wl('help:start') ?>">Help</a>
                    </li>
                    <?php if($INFO['isadmin']): ?>
                    <li class="nav-item">
                        <a class="nav-link py-1" href="<?php echo wl($ID, 'do=index') ?>">Index</a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- User Info -->
                <div class="mb-0 small">
                    Logged in as: <b><?php echo !empty($INFO['userinfo']) ? hsc($INFO['userinfo']['name']) : 'Guest'; ?></b>
                </div>
            </div>
	        </div>
	        
<?php
            // Help pages should show only their own content (no logbook action bar/list rendering).
            $isHelpNs = (getNS($ID) === 'help' || strncmp($ID, 'help:', 5) === 0 || $ID === 'help');
?>
	        <!-- Logbooks Row (Separate Line) -->
	        <div class="px-3 py-1 border-bottom" style="background-color: #f0f0f0;">
	             <div class="d-flex align-items-center">
	                <span class="fw-bold me-2 small">Logbooks:</span>
<?php 
                    $logbooks = elog_get_logbooks();
                    foreach($logbooks as $lb) {
                        $active = (stripos($ID, $lb) !== false) ? 'active' : '';
                        echo '<a class="btn btn-sm btn-outline-primary py-0 px-2 me-1 '.$active.'" href="'.wl($lb.':start').'">'.strtoupper($lb).'</a>';
                    }
                ?>
             </div>
        </div>
        
	        <!-- Actions Row -->
	        <div class="bg-light border-bottom py-1 px-3 d-flex justify-content-between align-items-center">
	             <div>
	                <!-- Navigation Buttons (Restricted to Logbook Start Page) -->
	                <?php if(!$isHelpNs && elog_is_logbook_root() && !empty($INFO['userinfo']) && auth_quickaclcheck($ID) >= AUTH_CREATE): ?>
	                    <a href="<?php echo wl($ID, 'do=newlog') ?>" class="btn btn-sm btn-primary me-1 py-0">New Log</a>
	                <?php endif; ?>
	                <?php if(!$isHelpNs && elog_is_logbook_root() && auth_quickaclcheck($ID) >= AUTH_READ): ?>
	                    <a href="<?php echo wl($ID, 'do=search') ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">Find</a>
	                    <a href="<?php echo wl($ID, 'do=photos') ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">Photos</a>
                    <a href="<?php echo wl($ID, 'do=rootplots') ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">ROOT Plots</a>
	                <?php endif; ?>

                <!-- Logbook Manager (Admin & Home only) -->
                <?php if($ID == $conf['start'] && $INFO['isadmin']): ?>
                    <button class="btn btn-success btn-sm py-0 me-2" onclick="createNewLogbook()">New Logbook</button>
                    <script>
                    function createNewLogbook() {
                        var name = prompt("Enter new logbook name (e.g. 2024-Run3):");
                        if (name) {
                            // Basic URL construction to avoid complex escaping issues with '__NAME__' in wl()
                            // We redirect to the root with ID
                            var base = "<?php echo wl() ?>";
                            var sep = base.indexOf('?') === -1 ? '?' : '&';
                            window.location.href = base + sep + "id=" + encodeURIComponent(name.trim()) + ":<?php echo $conf['start'] ?>&do=edit&new_logbook=1";
                        }
                    }
                    </script>
                <?php endif; ?>
                
                <?php if(empty($INFO['userinfo'])): ?>
                    <a href="<?php echo wl($ID, 'do=login') ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">Login</a>
                <?php else: ?>
                    <a href="<?php echo wl($ID, 'do=logout&sectok='.getSecurityToken()) ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">Logout</a>
                <?php endif; ?>

                <?php if($INFO['isadmin']): ?>
                    <a href="<?php echo wl($ID, 'do=edit') ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">Edit</a>
                <?php endif; ?>

                <?php if(elog_is_log_entry($ID) && !empty($INFO['userinfo']) && auth_quickaclcheck(getNS($ID).':*') >= AUTH_CREATE): ?>
                    <a href="<?php echo wl($ID, 'do=reply') ?>" class="btn btn-sm btn-outline-primary me-1 py-0">Reply</a>
                    <a href="<?php echo wl($ID, 'do=attach') ?>" class="btn btn-sm btn-outline-primary me-1 py-0">Add Attachment</a>
                <?php endif; ?>

                <?php if($INFO['isadmin']): ?>
                    <?php if($ACT == 'show' || $ACT == 'preview'): ?>
                         <a href="<?php echo wl($ID, 'do=delete_page&sectok='.getSecurityToken()) ?>" class="btn btn-sm btn-outline-danger me-1 py-0" onclick="return confirm('Permanently delete this page?');">Delete</a>
                    <?php endif; ?>
                    <a href="<?php echo wl($ID, 'do=admin') ?>" class="btn btn-sm btn-outline-secondary me-1 py-0">Configure</a>
                <?php endif; ?>
             </div>
             <div class="d-flex align-items-center">
                 <label class="form-check-label me-1 small text-muted" for="fixedWidthToggle">Fixed</label>
                 <div class="form-check form-switch mb-0">
                     <input class="form-check-input" type="checkbox" id="fixedWidthToggle"
                            <?php echo $fixedWidth ? 'checked' : ''; ?>
                            onchange="document.cookie='elog_fixed_width='+(this.checked?'1':'0')+';path=/;max-age=31536000';location.reload();">
                 </div>
             </div>
        </div>

        <!-- Main Content -->
        <div class="container-fluid mt-2 main-shell"> <!-- Reduced margin -->
            <div class="row">
                <div class="col-12">
                    <h5 class="my-2"> <!-- Reduced heading size and margins -->
                        <a href="<?php echo wl() ?>" class="elog-logo text-decoration-none">E-Log</a> : 
                        <?php tpl_pagetitle() ?>
                    </h5>
                    <!-- Removed hr to save space, relies on margin/padding separation of content below -->
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                     <?php tpl_flush() ?>
                     <?php tpl_includeFile('pageheader.html') ?>
                     
                     <?php 
                     /* Load elog helper functions - already loaded at top, but ensure safely included if needed elsewhere (include_once handles it) */
                     // include_once(dirname(__FILE__).'/elog_functions.php'); 
                     ?>

                     <div class="page-content shadow-sm p-3 <?php echo $isHomePage ? 'page-content-home' : ($isLogEntry ? 'page-content-entry' : ''); ?>"> <!-- Added padding inside content box -->

                         <?php 
                            if ($ACT == 'newlog_form' || defined('ELOG_NEWLOG_MODE')) {
                                $ns = getNS($ID);
                                $meta = elog_get_existing_metadata($ns);
                                elog_render_log_form($ns, $meta['categories'], $meta['tags'], 'new');
                            } elseif ($ACT == 'edit_form') {
                                $ns = getNS($ID);
                                $meta = elog_get_existing_metadata($ns);
                                $data = elog_get_entry_data($ID);
                                elog_render_log_form($ns, $meta['categories'], $meta['tags'], 'edit', $data);
                            } elseif ($ACT == 'attach_form') {
                                elog_render_attach_form($ID);
                            } elseif ($ACT == 'reply_form') {
                                $ns = getNS($ID);
                                $meta = elog_get_existing_metadata($ns);
                                $parent_data = elog_get_entry_data($ID);
                                $reply_data = [
                                    'type' => $parent_data['type'],
                                    'category' => $parent_data['category'],
                                    'tags' => '',
                                    'subject' => 'Re: ' . preg_replace('/^Re:\s*/i', '', $parent_data['subject']),
                                    'reply_to' => noNS($ID),
                                    'content' => ''
                                ];
                                elog_render_log_form($ns, $meta['categories'], $meta['tags'], 'new', $reply_data);
                            } elseif (in_array(($_REQUEST['do'] ?? ''), ['photos', 'rootplots'], true) && auth_quickaclcheck(getNS($ID) . ':*') < AUTH_READ) {
                                // The galleries read the media directory directly, so check read access here.
                                echo '<div class="alert alert-warning">You do not have permission to view this logbook.</div>';
                            } elseif ($ACT == 'photos' || ($_REQUEST['do'] ?? '') == 'photos') {
                                echo '<h4 class="mb-3 border-bottom pb-2">Photo Gallery</h4>';
                                elog_render_gallery(getNS($ID));
                            } elseif ($ACT == 'rootplots' || ($_REQUEST['do'] ?? '') == 'rootplots') {
                                echo '<h4 class="mb-3 border-bottom pb-2">ROOT Plots</h4>';
                                elog_render_root_gallery(getNS($ID));
                            } elseif ($ID == $conf['start'] && $ACT == 'show') {
                                tpl_content(false);
                                echo '<div class="home-logbook-grid">';

                                echo '<h5 class="text-muted text-uppercase fw-semibold mb-3" style="letter-spacing: 0.05em; font-size: 0.85rem;">Logbooks</h5>';
                                echo '<div class="row g-3">';
                                $logbooks = elog_get_logbooks();
                                $colors = ['#486090', '#2e7d32', '#c62828', '#6a1b9a', '#00838f', '#ef6c00', '#4527a0', '#00695c'];
                                $idx = 0;
                                foreach($logbooks as $lb) {
                                     $lb_start = $lb . ':' . $conf['start'];
                                     $title = p_get_metadata($lb_start, 'title');
                                     if (empty($title)) $title = strtoupper($lb);
                                     $color = $colors[$idx % count($colors)];
                                     $initial = strtoupper(substr($lb, 0, 1));

                                     // Count entries in this logbook
                                     $lb_dir = DOKU_INC . 'data/pages/' . utf8_encodeFN(str_replace(':', '/', $lb));
                                     $entry_count = 0;
                                     if (is_dir($lb_dir)) {
                                         $files = glob($lb_dir . '/*.txt');
                                         // Exclude 'start.txt'
                                         $entry_count = count(array_filter($files, function($f) { return basename($f) !== 'start.txt'; }));
                                     }

                                     echo '<div class="col-sm-6 col-md-4 col-lg-3">';
                                     echo '<div class="card h-100 border-0 shadow-sm" style="transition: transform 0.15s, box-shadow 0.15s; cursor: pointer;" onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 6px 20px rgba(0,0,0,0.12)\'" onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'\'">';
                                     echo '<a href="'.wl($lb.':start').'" class="text-decoration-none">';
                                     echo '<div class="card-body d-flex align-items-center p-3">';
                                     echo '<div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-3" style="width: 44px; height: 44px; background-color: '.$color.'; color: white; font-weight: 700; font-size: 1.1rem;">'.$initial.'</div>';
                                     echo '<div class="min-width-0">';
                                     echo '<div class="fw-semibold text-dark" style="font-size: 0.95rem;">'.hsc($title).'</div>';
                                     if ($entry_count >= 1000000) {
                                         $display_count = round($entry_count / 1000000, 1) . 'M';
                                     } elseif ($entry_count >= 1000) {
                                         $display_count = round($entry_count / 1000, 1) . 'K';
                                     } else {
                                         $display_count = (string)$entry_count;
                                     }
                                     echo '<small class="text-muted">'.$display_count.' entries</small>';
                                     echo '</div>';
                                     echo '</div>';
                                     echo '</a>';

                                     if ($INFO['isadmin']) {
                                         echo '<div class="card-footer bg-transparent border-top-0 pt-0 pb-2 px-3 text-end">';
                                         echo '<form action="'.wl($ID).'" method="post" class="d-inline" onsubmit="return confirm(\'Permanently delete logbook: '.hsc($lb).'?\');">';
                                         echo '<input type="hidden" name="do" value="delete_logbook" />';
                                         echo '<input type="hidden" name="logbook_name" value="'.hsc($lb).'" />';
                                         echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />';
                                         echo '<button type="submit" class="btn btn-sm text-danger border-0 p-0" style="font-size: 0.75rem; opacity: 0.6;" title="Delete Logbook" onmouseover="this.style.opacity=\'1\'" onmouseout="this.style.opacity=\'0.6\'">Delete</button>';
                                         echo '</form>';
                                         echo '</div>';
                                     }

                                     echo '</div>';
                                     echo '</div>';
                                     $idx++;
                                }
                                echo '</div></div>';
	                            } elseif (!$isHelpNs && elog_is_logbook_root() && $ACT == 'show') {
	                                // Only show content if it's not empty? 
	                                // For now, just render it but maybe with less padding/margin around it in CSS
	                                tpl_content(false); 
	                                echo '<hr class="elog_separator my-2">'; // Reduced margin further
	                                elog_render_list(); 
		                            } else {
	                                // Display Current Page URL (Click to Copy)
	                                $current_url = wl($ID, '', true, '&');
	                                echo '<div class="mb-2 text-muted small" style="cursor: pointer;" onclick="copyToClipboard(' . htmlspecialchars(json_encode($current_url)) . ')" title="Click to copy">';
	                                echo '<span class="me-1">🔗</span> ' . hsc($current_url);
	                                echo '</div>';
	                                echo '<script>
	                                function copyToClipboard(text) {
	                                    navigator.clipboard.writeText(text).then(function() {
	                                        alert("URL Copied: " + text); 
	                                    }).catch(function(err) {
	                                        console.error("Could not copy text: ", err);
	                                    });
	                                }
	                                </script>';

	                                // Only show ELOG metadata/thread UI on actual log entry pages (not Profile/Admin/etc).
	                                $isEntryView = elog_is_log_entry($ID) && ($ACT == 'show' || $ACT == 'preview');
	                                if ($isEntryView) {
	                                    elog_render_view_metadata();
	                                    elog_render_thread_context($ID);
	                                }

	                                // Buffer content to strip headers
	                                ob_start();
	                                tpl_content(false); 
	                                $content = ob_get_clean();
		                                if ($isEntryView) {
		                                    // Strip Type/Category/Tags/ReplyTo/Subject lines if they appear at the start.
		                                    $patterns = [
		                                        '/^\s*Type:.*?$/m',
		                                        '/^\s*Category:.*?$/m',
		                                        '/^\s*Tags:.*?$/m',
		                                        '/^\s*ReplyTo:.*?$/m',
		                                        '/^\s*RootPlots:.*?$/m',
		                                        '/^\s*Subject:.*?$/m'
		                                    ];
		                                    $content = preg_replace($patterns, '', $content, 1); // Remove only the first occurrence (header)
		                                }
	                                
	                                echo $content;
	                                
		                                // Render Attached Images and ROOT plots (log entries only)
		                                if ($isEntryView) {
		                                    elog_render_attachments($ID);
		                                    elog_render_root_plots($ID);
		                                }
		                            }
		                         ?>
		                     </div>

                     <?php tpl_includeFile('pagefooter.html') ?>
                </div>
            </div>
        </div>
        
        <footer class="mt-5 py-3 text-center text-muted border-top">
            <div class="mb-2">
                <?php
                    $target = (!empty($conf['target____extern'])) ? 'target="'.$conf['target____extern'].'"' : '';
                    tpl_license('button', true, false, false); // license button, no wrapper
                ?>
            </div>
            <small>
                ELOG theme by <a href="mailto:lim+elog-contact@cns.s.u-tokyo.ac.jp" class="text-muted text-decoration-none">Bong-Hwi Lim (UTokyo)</a>
                <br>
                Driver: DokuWiki
            </small>
        </footer>

    </div>
    <div class="no"><?php tpl_indexerWebBug() ?></div>
</body>
</html>
