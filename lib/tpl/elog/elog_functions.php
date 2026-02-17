<?php
/**
 * Helper functions for the elog template
 */

/**
 * Determine an author string from DokuWiki metadata.
 *
 * In some remote/XML-RPC setups, the `creator` field can be missing/empty even
 * though `last_change[user]` is present. Prefer creator, then last_change user.
 */
function elog_meta_author($meta) {
    $user = '';
    if (is_array($meta)) {
        $user = trim((string)($meta['creator'] ?? ''));
        if ($user === '') $user = trim((string)($meta['last_change']['user'] ?? ''));
        if ($user === '') $user = trim((string)($meta['contributor'] ?? ''));
        if ($user === '') $user = trim((string)($meta['user'] ?? ''));
    }
    if ($user === '') return 'Anonymous';

    // Prefer real name when available, but fall back to login name.
    if (function_exists('auth_getUserData')) {
        $ud = auth_getUserData($user);
        if (is_array($ud) && !empty($ud['name'])) return $ud['name'];
    }
    return $user;
}

/**
 * Parse an embedded Author: line from the raw page text.
 */
function elog_parse_author_line($raw_content) {
    if (!is_string($raw_content) || $raw_content === '') return '';
    if (preg_match('/^Author:\s*(.*)$/m', $raw_content, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

/**
 * Check if the current page is a logbook listing page
 * (i.e. the start page of a namespace, but not the root start page)
 */
function elog_is_logbook_root() {
    global $ID, $conf;
    
    // If we are at root, we are not in a logbook
    if ($ID == $conf['start']) return false;

    // Check if the current page name matches the start page name
    $idParts = explode(':', $ID);
    $pageName = array_pop($idParts);
    
    if ($pageName == $conf['start'] && count($idParts) > 0) {
        // Only treat namespaces under a known logbook as logbook roots.
        // This prevents system namespaces like help/ and archive/ from getting
        // logbook rendering/actions (New Log / Find / Photos, entry list, etc).
        $top = $idParts[0];
        $logbooks = elog_get_logbooks();
        return in_array($top, $logbooks, true);
    }
    
    // Also handle the case where the ID is exactly the namespace name (if default behavior is different)
    // But usually DokuWiki handles that by redirecting to :start
    
    return false;
}

/**
 * Get pages in the current namespace
 */
function elog_get_pages() {
    global $ID, $conf;
    
    $ns = getNS($ID);
    if (empty($ns)) return array();

    $dir = $conf['datadir'] . '/' . utf8_encodeFN(str_replace(':', '/', $ns));
    $data = array();
    
    if (is_dir($dir)) {
        $files = scandir($dir);
        // msg("Debug: Scanning dir $dir, found " . count($files) . " files.", 1);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            if (substr($file, -4) != '.txt') continue;
            
            $id = $ns . ':' . substr($file, 0, -4);
            
            // Skip the start page itself from the list
            if ($id == $ID) continue;

            $meta = p_get_metadata($id);
            $date = $meta['date']['created'] ?? filemtime($dir . '/' . $file);
            
            // Get raw content to parse Type, Category, Subject
            $raw_content = io_readFile($dir . '/' . $file);
            $user = elog_meta_author($meta);
            if ($user === 'Anonymous') {
                $author_line = elog_parse_author_line($raw_content);
                if ($author_line !== '') $user = $author_line;
            }
            
            // Default values
            $type = 'General';
            $category = 'General';
            $tags = '';
            $reply_to = '';
            $subject = $meta['title'] ?? noNS($id); // Default subject to title
            
            // Parse for "Type: ...", "Category: ...", "Subject: ..."
            // We look for these patterns at the beginning of lines
            if (preg_match('/^Type:\s*(.*)$/m', $raw_content, $matches)) {
                $type = trim($matches[1]);
            }
            if (preg_match('/^Category:\s*(.*)$/m', $raw_content, $matches)) {
                $category = trim($matches[1]);
            }
            if (preg_match('/^Tags:\s*(.*)$/m', $raw_content, $matches)) {
                $tags = trim($matches[1]);
            }
            if (preg_match('/^ReplyTo:\s*(.*)$/m', $raw_content, $matches)) {
                $reply_to = trim($matches[1]);
            }
            if (preg_match('/^Subject:\s*(.*)$/m', $raw_content, $matches)) {
                $subject = trim($matches[1]);
            } else {
                // If no "Subject:" line, rely on the first heading or ID
                 $instructions = p_get_instructions($raw_content);
                 // Find first header
                 foreach($instructions as $ins) {
                     if ($ins[0] == 'header') {
                         $subject = $ins[1][0];
                         break;
                     }
                 }
            }
            
            $data[] = array(
                'id' => noNS($id),
                'full_id' => $id,
                'date' => $date,
                'author' => $user,
                'subject' => $subject,
                'type' => $type,
                'category' => $category,
                'tags' => $tags,
                'reply_to' => $reply_to
            );
        }
    }
    
    // Check for "Subject: ..." or other artifacts in tags and clean them
    foreach ($data as &$page) {
        if (!empty($page['tags'])) {
             // Sometimes "Subject: ..." might be caught if regex is loose
             $page['tags'] = preg_replace('/Subject:.*$/', '', $page['tags']);
             $page['tags'] = trim($page['tags']);
        }
    }
    unset($page);
    
    // Sort Logic
    // We don't have $mode here unless passed as argument or retrieved from $_GET
    $mode = $_GET['mode'] ?? 'summary';

    if ($mode == 'threaded') {
        // Threaded mode sorting is handled by elog_build_threaded_list()
        // Default sort by date descending as base
        usort($data, function($a, $b) {
            return $b['date'] - $a['date'];
        });
    } else {
        // Default: Sort by Date Descending
        usort($data, function($a, $b) {
            return $b['date'] - $a['date'];
        });
    }
    
    return $data;
}

/**
 * Get existing metadata (Categories and Tags) from the current namespace
 */
function elog_get_existing_metadata($ns) {
    // We can reuse elog_get_pages, effectively it scans the current directory
    // Note: elog_get_pages relies on global $ID, so we might need to temporarily switch it 
    // or just rely on the fact that we are in the namespace. 
    // However, elog_get_pages implementation uses getNS($ID) effectively.
    
    $pages = elog_get_pages();
    $categories = array();
    $tags = array();
    
    foreach ($pages as $p) {
        if (!empty($p['category'])) {
            $categories[] = $p['category'];
        }
        if (!empty($p['tags'])) {
            // Tags can be comma separated
            $page_tags = explode(',', $p['tags']);
            foreach ($page_tags as $t) {
                $t = trim($t);
                if (!empty($t)) $tags[] = $t;
            }
        }
    }
    
    return array(
        'categories' => array_unique($categories),
        'tags' => array_unique($tags),
        // Initialize types if needed, though they are usually fixed
    );
}

/**
 * Render Metadata Table for View Page
 */
function elog_render_view_metadata() {
    global $ID, $conf;
    
    $file = wikiFN($ID);
    if (!file_exists($file)) return;
    
    $raw_content = io_readFile($file);
    
    $type = 'General';
    $category = 'General';
    $tags = '';
    
    if (preg_match('/^Type:\s*(.*)$/m', $raw_content, $matches)) $type = trim($matches[1]);
    if (preg_match('/^Category:\s*(.*)$/m', $raw_content, $matches)) $category = trim($matches[1]);
    if (preg_match('/^Tags:\s*(.*)$/m', $raw_content, $matches)) $tags = trim($matches[1]);
    
    // Clean tags
    $tags = preg_replace('/Subject:.*$/', '', $tags); // Safety
    $tag_list = array_filter(array_map('trim', explode(',', $tags)));
    
    // Subject
    $subject = p_get_metadata($ID, 'title');
    if (empty($subject)) {
         if (preg_match('/^Subject:\s*(.*)$/m', $raw_content, $matches)) $subject = trim($matches[1]);
         else $subject = noNS($ID);
    }

    // Base ID for filters (current namespace start page)
    $filter_base_id = getNS($ID) . ':' . $conf['start'];
    
    echo '<div class="card mb-3 shadow-sm">';
    echo '<div class="card-body p-2">';
    echo '<table class="table table-borderless table-sm mb-0">';
    echo '<tr>';
    
    // Subject
    echo '<td style="width: auto;"><strong>Subject:</strong> ';
    echo '<span class="fw-bold">' . hsc($subject) . '</span>';
    echo '</td>';
    
    // Type
    echo '<td style="width: auto;"><strong>Type:</strong> ';
    $url = wl($filter_base_id, ['filter_type' => $type]);
    echo '<a href="' . $url . '" class="badge bg-secondary text-decoration-none">' . hsc($type) . '</a>';
    echo '</td>';
    
    // Category
    echo '<td style="width: auto;"><strong>Category:</strong> ';
    $url = wl($filter_base_id, ['filter_category' => $category]);
    echo '<a href="' . $url . '" class="badge bg-info text-dark text-decoration-none">' . hsc($category) . '</a>';
    echo '</td>';
    
    // Tags
    echo '<td style="width: auto;"><strong>Tags:</strong> ';
    if (empty($tag_list)) {
        echo '<span class="text-muted small">None</span>';
    } else {
        foreach ($tag_list as $t) {
            $url = wl($filter_base_id, ['filter_tag' => $t]);
            echo '<a href="' . $url . '" class="badge bg-light text-dark border me-1 text-decoration-none">' . hsc($t) . '</a>';
        }
    }
    echo '</td>';
    
    echo '</tr>';
    echo '</table>';
    echo '</div></div>';
}

/**
 * Render the ELOG control bar (Search/Filter/Pagination)
 */
function elog_render_controls($categories, $current_filters) {
    global $ID;
    
    $years = range(date('Y'), 2020); 
    $months = array('01','02','03','04','05','06','07','08','09','10','11','12');
    $limits = array(20, 50, 100, 200);
    
    echo '<div class="card mb-3 bg-light">';
    echo '<div class="card-body p-2">';
    echo '<form action="' . wl($ID) . '" method="get" class="row g-2 align-items-center mb-0">';
    echo '<input type="hidden" name="id" value="' . $ID . '" />';
    echo '<input type="hidden" name="mode" value="' . ($current_filters['mode'] ?? 'summary') . '" />';
    if (!empty($current_filters['filter_tag'])) {
        echo '<input type="hidden" name="filter_tag" value="' . hsc($current_filters['filter_tag']) . '" />';
    }
    if (!empty($current_filters['filter_type'])) {
        echo '<input type="hidden" name="filter_type" value="' . hsc($current_filters['filter_type']) . '" />';
    }
    
    // Find ID
    echo '<div class="col-auto">';
    echo '<label class="visually-hidden">Find ID</label>';
    echo '<div class="input-group input-group-sm">';
    echo '<span class="input-group-text">ID</span>';
    echo '<input type="text" name="find_id" class="form-control" placeholder="Jump to..." value="' . hsc($current_filters['find_id']) . '" size="5">';
    echo '</div></div>';
    
    // Category Filter
    echo '<div class="col-auto">';
    echo '<select name="filter_category" class="form-select form-select-sm">';
    echo '<option value="">All Categories</option>';
    foreach ($categories as $cat) {
        $selected = ($current_filters['filter_category'] == $cat) ? 'selected="selected"' : '';
        echo '<option value="' . hsc($cat) . '" ' . $selected . '>' . hsc($cat) . '</option>';
    }
    echo '</select></div>';
    
    // Date Filter
    echo '<div class="col-auto">';
    echo '<div class="input-group input-group-sm">';
    echo '<select name="filter_year" class="form-select"><option value="">Year</option>';
    foreach ($years as $y) {
        $selected = ($current_filters['filter_year'] == $y) ? 'selected="selected"' : '';
        echo '<option value="' . $y . '" ' . $selected . '>' . $y . '</option>';
    }
    echo '</select>';
    echo '<select name="filter_month" class="form-select"><option value="">Mon</option>';
    foreach ($months as $m) {
        $selected = ($current_filters['filter_month'] == $m) ? 'selected="selected"' : '';
        echo '<option value="' . $m . '" ' . $selected . '>' . $m . '</option>';
    }
    echo '</select></div></div>';
    
    // Entries per page
    echo '<div class="col-auto ms-auto">';
    echo '<div class="input-group input-group-sm">';
    echo '<span class="input-group-text">Show</span>';
    echo '<select name="limit" class="form-select">';
    foreach ($limits as $l) {
        $selected = ($current_filters['limit'] == $l) ? 'selected="selected"' : '';
        echo '<option value="' . $l . '" ' . $selected . '>' . $l . '</option>';
    }
    echo '<option value="all" ' . ($current_filters['limit'] == 'all' ? 'selected="selected"' : '') . '>All</option>';
    echo '</select>';
    echo '</div></div>';
    
    echo '<div class="col-auto">';
    echo '<button type="submit" class="btn btn-sm btn-primary">Go</button>';
    echo '</div>';
    
    echo '</form>';
    
    // Show active filters (Type/Tag) if any
    if (!empty($current_filters['filter_tag']) || !empty($current_filters['filter_type'])) {
        echo '<div class="mt-2 small">';
        if (!empty($current_filters['filter_type'])) {
             echo '<span class="me-2">Type: <strong>' . hsc($current_filters['filter_type']) . '</strong> <a href="' . wl($ID, array_diff_key($_GET, ['filter_type' => ''])) . '" class="text-danger">&times;</a></span>';
        }
        if (!empty($current_filters['filter_tag'])) {
             echo '<span class="me-2">Tag: <strong>' . hsc($current_filters['filter_tag']) . '</strong> <a href="' . wl($ID, array_diff_key($_GET, ['filter_tag' => ''])) . '" class="text-danger">&times;</a></span>';
        }
        echo '</div>';
    }
    
    echo '</div></div>';
}

/**
 * Build a threaded (tree) list from flat pages array
 * Returns flat array with thread_depth and reply_count added
 */
function elog_build_threaded_list($pages) {
    // Index pages by their numeric ID
    $by_id = [];
    foreach ($pages as &$p) {
        $by_id[$p['id']] = &$p;
    }
    unset($p);

    // Build children map and count replies
    $children = [];
    foreach ($pages as &$p) {
        $p['reply_count'] = 0;
        $p['thread_depth'] = 0;
        if (!empty($p['reply_to']) && isset($by_id[$p['reply_to']])) {
            $children[$p['reply_to']][] = $p['id'];
        }
    }
    unset($p);

    // Count direct replies for each entry
    foreach ($children as $parent_id => $child_ids) {
        if (isset($by_id[$parent_id])) {
            $by_id[$parent_id]['reply_count'] = count($child_ids);
        }
    }

    // Find root entries (no reply_to, or reply_to parent not in filtered set)
    $roots = [];
    foreach ($pages as $p) {
        if (empty($p['reply_to']) || !isset($by_id[$p['reply_to']])) {
            $roots[] = $p['id'];
        }
    }

    // Sort roots by date descending (newest first)
    usort($roots, function($a, $b) use ($by_id) {
        return $by_id[$b]['date'] - $by_id[$a]['date'];
    });

    // DFS to build flat threaded list
    $result = [];
    $stack = [];
    // Push roots in reverse so first root is processed first
    for ($i = count($roots) - 1; $i >= 0; $i--) {
        $stack[] = ['id' => $roots[$i], 'depth' => 0];
    }

    $visited = [];
    while (!empty($stack)) {
        $item = array_pop($stack);
        $id = $item['id'];
        $depth = $item['depth'];

        if (isset($visited[$id]) || !isset($by_id[$id])) continue;
        $visited[$id] = true;

        $entry = $by_id[$id];
        $entry['thread_depth'] = min($depth, 10);
        $result[] = $entry;

        // Push children (sorted by date ascending, reversed for stack)
        if (isset($children[$id])) {
            $child_ids = $children[$id];
            usort($child_ids, function($a, $b) use ($by_id) {
                return $by_id[$b]['date'] - $by_id[$a]['date'];
            });
            foreach ($child_ids as $cid) {
                $stack[] = ['id' => $cid, 'depth' => $depth + 1];
            }
        }
    }

    return $result;
}

/**
 * Render the ELOG list
 */
function elog_render_list() {
    global $ID;
    
    $all_pages = elog_get_pages();

    
    // Get parameters
    $mode = $_GET['mode'] ?? 'summary';
    $find_id = $_GET['find_id'] ?? '';
    $filter_category = $_GET['filter_category'] ?? '';
    $filter_type = $_GET['filter_type'] ?? '';
    $filter_tag = $_GET['filter_tag'] ?? '';
    $filter_year = $_GET['filter_year'] ?? '';
    $filter_month = $_GET['filter_month'] ?? '';
    $limit = $_GET['limit'] ?? 20;
    $page_num = $_GET['page'] ?? 1;
    
    if ($limit == 'all') $limit = count($all_pages);
    if ($limit < 1) $limit = 20;
    
    // Extract Categories for dropdown
    $categories = array_unique(array_map(function($p) { return $p['category']; }, $all_pages));
    sort($categories);
    
    // Filter Logic
    $filtered_pages = array_filter($all_pages, function($p) use ($find_id, $filter_category, $filter_type, $filter_tag, $filter_year, $filter_month) {
        // ID Filter
        if (!empty($find_id) && stripos($p['id'], $find_id) === false) return false;
        
        // Category Filter
        if (!empty($filter_category) && $p['category'] != $filter_category) return false;
        
        // Type Filter
        if (!empty($filter_type) && $p['type'] != $filter_type) return false;
        
        // Tag Filter (contains)
        if (!empty($filter_tag)) {
            // Check if tag exists in comma-separated list
            $ptags = array_map('trim', explode(',', $p['tags']));
            if (!in_array($filter_tag, $ptags)) return false;
        }
        
        // Date Filter
        if (!empty($filter_year) && date('Y', $p['date']) != $filter_year) return false;
        if (!empty($filter_month) && date('m', $p['date']) != $filter_month) return false;
        
        return true;
    });
    
    // Debug
    // msg("Debug: All Pages: " . count($all_pages) . ", Filtered Pages: " . count($filtered_pages), 1);
    // msg("Debug: Filters - Type: '$filter_type', Tag: '$filter_tag', Cat: '$filter_category', Year: '$filter_year', Month: '$filter_month', Find ID: '$find_id'", 1);
    
    // Build threaded list if in threaded mode (before pagination)
    if ($mode == 'threaded') {
        $filtered_pages = elog_build_threaded_list($filtered_pages);
    }

    // Pagination Logic
    $total_entries = count($filtered_pages);
    $total_pages = ceil($total_entries / $limit);
    if ($page_num > $total_pages) $page_num = $total_pages;
    if ($page_num < 1) $page_num = 1;

    $offset = ($page_num - 1) * $limit;
    $display_pages = array_slice($filtered_pages, $offset, $limit);
    
    // Render
    elog_render_controls($categories, compact('mode', 'find_id', 'filter_category', 'filter_type', 'filter_tag', 'filter_year', 'filter_month', 'limit'));
    
    // Mode Switcher
    echo '<div class="mb-2">';
    echo '<span class="fw-bold me-2">Mode:</span>';
    $modes = ['full' => 'Full', 'summary' => 'Summary', 'threaded' => 'Threaded'];
    $links = [];
    foreach ($modes as $m => $label) {
        if ($mode == $m) {
            $links[] = '<span class="text-dark fw-bold">' . $label . '</span>';
        } else {
            $links[] = '<a href="' . wl($ID, array_merge($_GET, ['mode' => $m])) . '">' . $label . '</a>';
        }
    }
    echo implode(' | ', $links);
    echo '</div>';

    if (empty($display_pages)) {
        echo '<div class="alert alert-info">No matching entries found.</div>';
        return;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-bordered table-hover table-sm">';
    
    // Header
    echo '<thead class="table-light">';
    echo '<tr>';
    echo '<th style="width: 5%">ID</th>';
    echo '<th style="width: 15%">Date</th>';
    echo '<th style="width: 10%">Author</th>';
    echo '<th style="width: 10%">Type</th>';
    echo '<th style="width: 10%">Category</th>';
    echo '<th style="width: 50%">Subject</th>';
    echo '</tr>';
    echo '</thead><tbody>';
    
    foreach ($display_pages as $page) {
        $dateStr = date('D M d H:i:s Y', $page['date']);
        
        echo '<tr>';
        echo '<td class="text-center"><a href="' . wl($page['full_id']) . '">' . hsc($page['id']) . '</a></td>';
        echo '<td class="text-nowrap">' . $dateStr . '</td>';
        echo '<td>' . hsc($page['author']) . '</td>';
        echo '<td>' . hsc($page['type']) . '</td>';
        echo '<td>' . hsc($page['category']) . '</td>';
        echo '<td>';
        $thread_depth = $page['thread_depth'] ?? 0;
        if ($thread_depth > 0 && $mode == 'threaded') {
            echo '<span class="thread-indent" style="padding-left:' . ($thread_depth * 20) . 'px">';
            echo '<span class="text-muted">└</span> ';
        }
        echo '<a href="' . wl($page['full_id']) . '">' . hsc($page['subject']) . '</a>';
        $reply_count = $page['reply_count'] ?? 0;
        if ($reply_count > 0 && $mode == 'threaded') {
            echo ' <span class="badge bg-info text-dark reply-badge">' . $reply_count . ' repl' . ($reply_count == 1 ? 'y' : 'ies') . '</span>';
        }
        if ($thread_depth > 0 && $mode == 'threaded') {
            echo '</span>';
        }
        echo '</td>';
        echo '</tr>';
        
        // Full Content
        if ($mode == 'full') {
             echo '<tr><td colspan="6" class="p-3 bg-white">';
             echo '<div class="border rounded p-3 bg-light">';
             // Tags
             if (!empty($page['tags'])) {
                 $tag_list = array_map('trim', explode(',', $page['tags']));
                 echo '<div class="mb-2">';
                 foreach ($tag_list as $t) {
                     if ($t !== '') echo '<span class="badge bg-info text-dark me-1">' . hsc($t) . '</span>';
                 }
                 echo '</div>';
             }
             // Render content without metadata headers (Type, Category, Tags, Subject)
             $raw = rawWiki($page['full_id']);
             $raw = preg_replace('/^(Type|Category|Subject|Tags|ReplyTo|RootPlots):\s*.*\n?/mi', '', $raw);
             echo p_render('xhtml', p_get_instructions($raw), $info);
             // Attached images
             elog_render_attachments_inline($page['full_id']);
             echo '</div>';
             echo '</td></tr>';
        }
    }
    echo '</tbody></table></div>';
    
    // Pagination Controls
    if ($total_pages > 1) {
        echo '<nav aria-label="Page navigation">';
        echo '<ul class="pagination pagination-sm justify-content-end">';
        
        // Previous
        $prevClass = ($page_num <= 1) ? 'disabled' : '';
        echo '<li class="page-item ' . $prevClass . '">';
        echo '<a class="page-link" href="' . wl($ID, array_merge($_GET, array('page' => $page_num - 1))) . '">Previous</a>';
        echo '</li>';
        
        // Page Numbers
        // Simple range for now, could be improved for many pages
        for ($i = 1; $i <= $total_pages; $i++) {
            $activeClass = ($i == $page_num) ? 'active' : '';
            echo '<li class="page-item ' . $activeClass . '">';
            echo '<a class="page-link" href="' . wl($ID, array_merge($_GET, array('page' => $i))) . '">' . $i . '</a>';
            echo '</li>';
        }
        
        // Next
        $nextClass = ($page_num >= $total_pages) ? 'disabled' : '';
        echo '<li class="page-item ' . $nextClass . '">';
        echo '<a class="page-link" href="' . wl($ID, array_merge($_GET, array('page' => $page_num + 1))) . '">Next</a>';
        echo '</li>';
        
        echo '</ul></nav>';
    }
}


/**
 * Check if the ID is a log entry (numeric ID)
 */
/**
 * Check if the ID is a log entry (numeric ID)
 */
function elog_is_log_entry($id) {
    if (strpos($id, ':') === false) return false;
    $parts = explode(':', $id);
    $name = end($parts);
    return is_numeric($name); 
}

/**
 * Parse existing log entry content
 */
function elog_get_entry_data($id) {
    $raw = rawWiki($id);
    // Normalize newlines
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    
    $data = [
        'type' => 'General',
        'category' => 'General',
        'tags' => '',
        'reply_to' => '',
        'root_plots' => '',
        'subject' => '',
        'content' => $raw
    ];
    
    $lines = explode("\n", $raw);
    $body_start = 0;
    
    foreach ($lines as $i => $line) {
        $trim_line = trim($line);
        if (preg_match('/^Type:\s*(.*)/iu', $trim_line, $m)) $data['type'] = trim($m[1]);
        elseif (preg_match('/^Category:\s*(.*)/iu', $trim_line, $m)) $data['category'] = trim($m[1]);
        elseif (preg_match('/^Tags:\s*(.*)/iu', $trim_line, $m)) $data['tags'] = trim($m[1]);
        elseif (preg_match('/^ReplyTo:\s*(.*)/iu', $trim_line, $m)) $data['reply_to'] = trim($m[1]);
        elseif (preg_match('/^RootPlots:\s*(.*)/iu', $trim_line, $m)) $data['root_plots'] = trim($m[1]);
        elseif (preg_match('/^Subject:\s*(.*)/iu', $trim_line, $m)) $data['subject'] = trim($m[1]);
        elseif (preg_match('/^======\s*(.*)\s*======/u', $trim_line, $m)) {
            if (empty($data['subject'])) $data['subject'] = trim($m[1]);
            $body_start = $i + 1;
            break; // End of headers
        }
    }
    
    // Remaining is body
    if ($body_start > 0 && $body_start < count($lines)) {
        $data['content'] = implode("\n", array_slice($lines, $body_start));
        $data['content'] = trim($data['content']);
    }
    
    return $data;
}

/**
 * Render thread context (parent chain + direct replies) for a log entry
 */
function elog_render_thread_context($id) {
    if (!elog_is_log_entry($id)) return;

    $ns = getNS($id);
    $current_num = noNS($id);
    $entry_data = elog_get_entry_data($id);

    // Build ancestor chain (walk up ReplyTo)
    $ancestors = [];
    $visited = [$current_num => true];
    $parent_num = $entry_data['reply_to'];
    $depth = 0;
    while (!empty($parent_num) && $depth < 10) {
        if (isset($visited[$parent_num])) break; // cycle prevention
        $visited[$parent_num] = true;
        $parent_id = $ns . ':' . $parent_num;
        if (!page_exists($parent_id)) break;
        $pdata = elog_get_entry_data($parent_id);
        array_unshift($ancestors, [
            'id' => $parent_num,
            'full_id' => $parent_id,
            'subject' => $pdata['subject'],
            'reply_to' => $pdata['reply_to']
        ]);
        $parent_num = $pdata['reply_to'];
        $depth++;
    }

    // Find direct replies (entries whose reply_to == current_num)
    global $conf;
    $dir = $conf['datadir'] . '/' . utf8_encodeFN(str_replace(':', '/', $ns));
    $replies = [];
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if (substr($file, -4) != '.txt') continue;
            $name = substr($file, 0, -4);
            if (!is_numeric($name) || $name == $current_num) continue;
            $raw = io_readFile($dir . '/' . $file);
            if (preg_match('/^ReplyTo:\s*(.*)$/m', $raw, $m) && trim($m[1]) == $current_num) {
                $child_id = $ns . ':' . $name;
                $child_subject = '';
                if (preg_match('/^Subject:\s*(.*)$/m', $raw, $sm)) $child_subject = trim($sm[1]);
                $child_meta = p_get_metadata($child_id);
                $child_author = elog_meta_author($child_meta);
                if ($child_author === 'Anonymous') {
                    $author_line = elog_parse_author_line($raw);
                    if ($author_line !== '') $child_author = $author_line;
                }
                $replies[] = [
                    'id' => $name,
                    'full_id' => $child_id,
                    'subject' => $child_subject ?: $name,
                    'author' => $child_author,
                    'date' => $child_meta['date']['created'] ?? filemtime($dir . '/' . $file)
                ];
            }
        }
        usort($replies, function($a, $b) { return $a['date'] - $b['date']; });
    }

    if (empty($ancestors) && empty($replies)) return;

    echo '<div class="card mb-3 shadow-sm thread-context-card">';
    echo '<div class="card-header bg-light py-2"><strong>Thread</strong></div>';
    echo '<div class="card-body p-2">';

    // Ancestor chain
    if (!empty($ancestors)) {
        foreach ($ancestors as $i => $anc) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $i);
            $connector = ($i > 0) ? '<span class="text-muted">└</span> ' : '';
            echo '<div class="small">' . $indent . $connector;
            echo '<a href="' . wl($anc['full_id']) . '">#' . hsc($anc['id']) . '</a> — ';
            echo '<a href="' . wl($anc['full_id']) . '">' . hsc($anc['subject']) . '</a>';
            echo '</div>';
        }
        // Current entry
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', count($ancestors));
        echo '<div class="small fw-bold">' . $indent . '<span class="text-muted">└</span> ';
        echo '#' . hsc($current_num) . ' — ' . hsc($entry_data['subject']);
        echo ' <span class="badge bg-secondary">current</span>';
        echo '</div>';
    }

    // Direct replies
    if (!empty($replies)) {
        if (!empty($ancestors)) echo '<hr class="my-2">';
        echo '<div class="small text-muted mb-1"><strong>Replies (' . count($replies) . '):</strong></div>';
        foreach ($replies as $rep) {
            echo '<div class="small ms-3">';
            echo '<span class="text-muted">└</span> ';
            echo '<a href="' . wl($rep['full_id']) . '">#' . hsc($rep['id']) . '</a> — ';
            echo '<a href="' . wl($rep['full_id']) . '">' . hsc($rep['subject']) . '</a>';
            echo ' <span class="text-muted">by ' . hsc($rep['author']) . ', ' . date('Y-m-d H:i', $rep['date']) . '</span>';
            echo '</div>';
        }
    }

    echo '</div></div>';
}

/**
 * Get data from an existing log entry
 */
// Helper functions removed (elog_get_entry_data, etc) as we reverted to creation-only mode.
function elog_handle_actions() {
    global $ID, $conf, $ACT, $INFO;
    
    $do = $_REQUEST['do'] ?? '';
    
    // 1. Show New Log Form
    if ($do == 'newlog') {
        if (!defined('ELOG_NEWLOG_MODE')) define('ELOG_NEWLOG_MODE', 1);
        $ACT = 'newlog_form';
        return;
    }
    
    // 1b. Show Reply Form
    if ($do == 'reply' && elog_is_log_entry($ID)) {
        if (auth_quickaclcheck(getNS($ID) . ':*') < AUTH_CREATE) {
            return;
        }
        $ACT = 'reply_form';
        return;
    }

    // 2. Intercept Edit for Log Entries
    if ($do == 'edit' && elog_is_log_entry($ID)) {
        if (auth_quickaclcheck($ID) < AUTH_EDIT) {
            return; 
        }
        $ACT = 'edit_form';
        return;
    }
    
    // 3. Save New Log
    if ($do == 'newlog_save') {
        if (!checkSecurityToken()) return;
        
        $ns = getNS($ID);
        if (empty($ns)) {
             msg("Error: No namespace selected.", -1);
             return;
        }
        
        // Calculate New ID
        $dir = $conf['datadir'] . '/' . utf8_encodeFN(str_replace(':', '/', $ns));
        $max_id = 0;
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if (substr($file, -4) != '.txt') continue;
                $name = substr($file, 0, -4);
                if (is_numeric($name)) {
                    $val = intval($name);
                    if ($val > $max_id) $max_id = $val;
                }
            }
        }
        $page_id = $ns . ':' . ($max_id + 1);
        
        // Construct Content
        $subject = trim($_POST['log_subject'] ?? ''); 
        $type = trim($_POST['log_type'] ?? 'General');
        $category = trim($_POST['log_category'] ?? '');
        if ($category === '___new___') {
            $category = trim($_POST['log_category_new'] ?? '');
        }
        $tags = trim($_POST['log_tags_input'] ?? '');
        $reply_to = trim($_POST['log_reply_to'] ?? '');
        $content_body = $_POST['log_content'] ?? '';

        if (empty($subject)) $subject = 'Untitled';

        $content = "Type: " . $type . "\n";
        if (!empty($category)) {
            $content .= "Category: " . $category . "\n";
        }
        $content .= "Tags: " . $tags . "\n";
        if (!empty($reply_to)) {
            $content .= "ReplyTo: " . $reply_to . "\n";
        }
        $root_plots = trim($_POST['root_plots'] ?? '');
        if (!empty($root_plots)) {
            $content .= "RootPlots: " . $root_plots . "\n";
        }
        $content .= "Subject: " . $subject . "\n";
        $content .= "====== " . $subject . " ======\n\n";
        $content .= $content_body;

        saveWikiText($page_id, $content, "New Log Entry");

        // Handle Images (Creation)
        elog_handle_image_upload($page_id, ($max_id + 1));

        // Rename TEMP_ ROOT files to permanent names
        elog_rename_temp_root_files($page_id, ($max_id + 1));

        send_redirect(wl($page_id, '', true, '&'));
    }
    
    // 4. Save Edited Log
    if ($do == 'edit_save') {
        if (!checkSecurityToken()) return;
        
        $real_id = trim($_POST['real_id'] ?? '');
        if (empty($real_id) || !page_exists($real_id)) return;
        
        if (auth_quickaclcheck($real_id) < AUTH_EDIT) return;
        
        // Construct Content (Duplicated logic, kept simple)
        $subject = trim($_POST['log_subject'] ?? ''); 
        $type = trim($_POST['log_type'] ?? 'General');
        $category = trim($_POST['log_category'] ?? '');
        if ($category === '___new___') {
            $category = trim($_POST['log_category_new'] ?? '');
        }
        $tags = trim($_POST['log_tags_input'] ?? '');
        $reply_to = trim($_POST['log_reply_to'] ?? '');
        $content_body = $_POST['log_content'] ?? '';

        if (empty($subject)) $subject = 'Untitled';

        $content = "Type: " . $type . "\n";
        if (!empty($category)) {
            $content .= "Category: " . $category . "\n";
        }
        $content .= "Tags: " . $tags . "\n";
        if (!empty($reply_to)) {
            $content .= "ReplyTo: " . $reply_to . "\n";
        }
        $root_plots = trim($_POST['root_plots'] ?? '');
        if (!empty($root_plots)) {
            $content .= "RootPlots: " . $root_plots . "\n";
        }
        $content .= "Subject: " . $subject . "\n";
        $content .= "====== " . $subject . " ======\n\n";
        $content .= $content_body;

        saveWikiText($real_id, $content, "Edited Log Entry");
        
        // Handle Deletions (Admin Only)
        if ($INFO['isadmin'] && !empty($_POST['delete_images'])) {
            $media_root = $conf['mediadir'];
            $ns_dir = utf8_encodeFN(str_replace(':', '/', getNS($real_id)));
            $upload_dir = $media_root . '/' . $ns_dir;
            
            foreach ($_POST['delete_images'] as $del_img) {
                // Security: Clean ID and ensure it belongs to this page?
                // The filename format "100_..." implicitly binds it, but we should be careful.
                // We should only delete files that exist in the directory.
                $del_name = cleanID($del_img);
                // Ensure no traversal
                $del_name = basename($del_name);
                
                $file_path = $upload_dir . '/' . $del_name;
                if (file_exists($file_path)) {
                    @unlink($file_path);
                    // Also delete meta if exists? 
                    // @unlink($conf['metadir'] . '/' . $ns_dir . '/' . $del_name . '.meta');
                }
            }
        }
        
        // Handle Images (Append)
        // Extract number from ID e.g. elogtest:10 -> 10
        $parts = explode(':', $real_id);
        $num_part = end($parts); // "10"
        elog_handle_image_upload($real_id, $num_part);

        // Rename TEMP_ ROOT files to permanent names
        elog_rename_temp_root_files($real_id, $num_part);

        send_redirect(wl($real_id, '', true, '&'));
    }

    // 5. Upload ROOT file temporarily (AJAX)
    if ($do == 'upload_root_temp') {
        header('Content-Type: application/json');

        if (empty($_FILES['root_file']['name']) || $_FILES['root_file']['error'] != 0) {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
            exit;
        }

        $ns = trim($_POST['ns'] ?? '');
        if (empty($ns)) {
            echo json_encode(['success' => false, 'error' => 'No namespace']);
            exit;
        }

        $filename = basename($_FILES['root_file']['name']);
        if (!preg_match('/\.root$/i', $filename)) {
            echo json_encode(['success' => false, 'error' => 'Not a .root file']);
            exit;
        }

        $media_root = $conf['mediadir'];
        $ns_dir = utf8_encodeFN(str_replace(':', '/', $ns));
        $upload_dir = $media_root . '/' . $ns_dir;
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $clean_name = cleanID($filename);
        $temp_name = 'temp_' . time() . '_' . $clean_name;
        $target_path = $upload_dir . '/' . $temp_name;

        if (move_uploaded_file($_FILES['root_file']['tmp_name'], $target_path)) {
            $media_id = $ns . ':' . $temp_name;
            $url = ml($media_id);
            echo json_encode(['success' => true, 'filename' => $filename, 'temp_name' => $temp_name, 'url' => $url]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Upload failed']);
        }
        exit;
    }
}

/**
 * Helper to handle image uploads
 */
function elog_handle_image_upload($page_id, $log_num) {
    global $conf;
    if (!empty($_FILES['log_images']['name'][0])) {
        $media_root = $conf['mediadir'];
        $ns_dir = utf8_encodeFN(str_replace(':', '/', getNS($page_id)));
        $upload_dir = $media_root . '/' . $ns_dir;
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $count = count($_FILES['log_images']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['log_images']['error'][$i] == 0) {
                $tmp_name = $_FILES['log_images']['tmp_name'][$i];
                $name = basename($_FILES['log_images']['name'][$i]);
                $name = cleanID($name);
                $target_name = $log_num . '_' . time() . '_' . $i . '_' . $name;
                $target_path = $upload_dir . '/' . $target_name;
                move_uploaded_file($tmp_name, $target_path);
            }
        }
    }
}

/**
 * Rename TEMP_ ROOT files to permanent names for a saved entry
 */
function elog_rename_temp_root_files($page_id, $log_num) {
    global $conf;
    $media_root = $conf['mediadir'];
    $ns_dir = utf8_encodeFN(str_replace(':', '/', getNS($page_id)));
    $upload_dir = $media_root . '/' . $ns_dir;

    if (!is_dir($upload_dir)) return;

    $files = scandir($upload_dir);
    $index = 0;
    foreach ($files as $file) {
        if (strpos($file, 'temp_') === 0 && preg_match('/\.root$/i', $file)) {
            // Extract original filename from temp_{timestamp}_{cleanname}
            $parts = explode('_', $file, 3);
            $clean_name = isset($parts[2]) ? $parts[2] : $file;
            $new_name = $log_num . '_' . time() . '_' . $index . '_' . $clean_name;
            rename($upload_dir . '/' . $file, $upload_dir . '/' . $new_name);
            $index++;
        }
    }
}


/**
 * Render the Log Form (New or Edit)
 */
function elog_render_log_form($ns, $categories, $tags, $mode='new', $data=[]) {
    global $ID;
    
    // Defaults
    $def_type = $data['type'] ?? 'General';
    $def_cat = $data['category'] ?? 'General';
    $subject = $data['subject'] ?? '';
    $content = $data['content'] ?? '';
    
    // Determine Action
    if ($mode == 'edit') {
        $page_title = "Edit Log Entry";
        $btn_text = "Save Changes";
        
        // Target a dummy edit page
        $target_ns = getNS($ID);
        if (empty($target_ns)) $target_ns = $ID;
        $action_id = $target_ns . ':edit_entry_submission';
        $do_action = 'edit_save';
    } else {
        $page_title = "Create New Log Entry";
        $btn_text = "Create Log";
        
        $target_ns = $ns;
        if (empty($target_ns)) $target_ns = getNS($ID);
        if (empty($target_ns)) $target_ns = $ID;
        $action_id = $target_ns . ':new_entry_submission'; 
        $do_action = 'newlog_save';
    }
    
    $action_url = "?id=" . $action_id . "&do=" . $do_action;

    // Define standard types
    $types = ['General', 'Shift Summary', 'Maintenance', 'Error/Fault', 'Standard Run', 'Calibration', 'Test'];
    
    sort($categories);
    sort($tags);
    
    // Add EasyMDE CSS/JS
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">';
    echo '<script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>';
    
    echo '<div class="card shadow-sm">';
    echo '<div class="card-header bg-primary text-white">' . hsc($page_title) . '</div>';
    echo '<div class="card-body">';
    
    echo '<form action="'.$action_url.'" method="post" class="mt-3" enctype="multipart/form-data" id="log_form">';
    echo '<input type="hidden" name="do" value="' . $do_action . '" />';
    echo '<input type="hidden" name="real_id" value="' . $ID . '" />'; // Keep real ID for edit save
    echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />';
    $reply_to_val = $data['reply_to'] ?? '';
    echo '<input type="hidden" name="log_reply_to" value="' . hsc($reply_to_val) . '" />';

    if (!empty($reply_to_val)) {
        $parent_ns = $ns ?? getNS($ID);
        $parent_id = $parent_ns . ':' . $reply_to_val;
        $parent_subject = '';
        if (page_exists($parent_id)) {
            $parent_data = elog_get_entry_data($parent_id);
            $parent_subject = $parent_data['subject'];
        }
        echo '<div class="alert alert-info py-2 mb-3">';
        echo '<strong>Replying to:</strong> <a href="' . wl($parent_id) . '">#' . hsc($reply_to_val) . '</a>';
        if (!empty($parent_subject)) echo ' — ' . hsc($parent_subject);
        echo '</div>';
    }
    
    // Row 1: Type & Category
    echo '<div class="row mb-3">';
    
    // Type
    echo '<div class="col-md-6">';
    echo '<label for="log_type" class="form-label fw-bold">Type</label>';
    echo '<select class="form-select" id="log_type" name="log_type">';
    foreach ($types as $t) {
        $sel = ($t == $def_type) ? 'selected' : '';
        echo '<option value="' . hsc($t) . '" '.$sel.'>' . hsc($t) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    
    // Category
    echo '<div class="col-md-6">';
    echo '<label for="log_category" class="form-label fw-bold">Category</label>';
    echo '<select class="form-select" id="log_category" name="log_category" onchange="toggleNewCategory(this)">';
    $found_cat = false;
    $none_sel = empty($def_cat) ? 'selected' : '';
    echo '<option value="" '.$none_sel.'>-- None --</option>';
    foreach ($categories as $cat) {
         if (empty($cat)) continue;
         $sel = ($cat == $def_cat) ? 'selected' : '';
         if ($sel) $found_cat = true;
         echo '<option value="' . hsc($cat) . '" '.$sel.'>' . hsc($cat) . '</option>';
    }
    echo '<option value="___new___">-- Add New --</option>';
    echo '</select>';
    echo '<input type="text" class="form-control mt-2 d-none" id="log_category_new" name="log_category_new" placeholder="Enter new category name">';
    echo '</div>';
    
    echo '</div>'; // End Row 1
    
    // Subject
    echo '<div class="mb-3">';
    echo '<label for="log_subject" class="form-label fw-bold">Subject</label>';
    echo '<input type="text" class="form-control" id="log_subject" name="log_subject" value="'.hsc($subject).'" required>';
    echo '</div>';
    
    // Tags (Chips UI)
    echo '<div class="mb-3 position-relative">';
    echo '<label for="log_tags_visual" class="form-label fw-bold">Tags</label>';
    echo '<div class="form-control p-2" id="tags_container" style="min-height: 38px; display: flex; flex-wrap: wrap; gap: 5px; align-items: center;" onclick="document.getElementById(\'log_tags_input\').focus()">';
    echo '<input type="text" id="log_tags_input" style="border: none; outline: none; flex-grow: 1; min-width: 100px;" placeholder="Type tags here..." autocomplete="off">';
    echo '</div>';
    echo '<input type="hidden" name="log_tags_input" id="log_tags_hidden" value="">';
    
    // Suggestions
    echo '<div id="tags_suggestions" class="list-group position-absolute shadow-sm" style="display:none; z-index: 1000; width: 300px; max-height: 200px; overflow-y: auto;"></div>';
    
    echo '<div class="mt-2">';
    echo '<small class="text-muted">Available Tags (click to add):</small><br>';
    echo '<div id="available_tags_list">';
    foreach ($tags as $tag) {
        echo '<span class="badge bg-light text-dark border me-1 tag-suggestion" style="cursor:pointer;" onclick="addTag(\'' . hsc($tag) . '\')">' . hsc($tag) . '</span>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Existing Images (Edit Mode Only by Logic in Handler, but we render upload field always)
    // If edit mode, show current attachments
    if ($mode == 'edit') {
        global $INFO;
        echo '<div class="mb-3">';
        echo '<label class="form-label fw-bold">Current Images</label>';
        elog_render_attachments($ID, $INFO['isadmin']); 
        if ($INFO['isadmin']) {
             echo '<div class="mt-2 text-warning small"><i class="bi bi-exclamation-triangle"></i> Checked images will be permanently deleted upon saving.</div>';
        }
        echo '</div>';
    }
    
    // CSS for Drop Zone
    echo '<style>
        .upload-drop-zone {
            border: 2px dashed #ccc;
            border-radius: 5px;
            padding: 30px;
            text-align: center;
            color: #aaa;
            cursor: pointer;
            transition: all 0.2s;
            background: #f9f9f9;
        }
        .upload-drop-zone:hover, .upload-drop-zone.drag-over {
            border-color: #0d6efd;
            background: #e9f2ff;
            color: #0d6efd;
        }
        .file-preview-item {
            display: flex;
            align-items: center;
            justify-content: auto;
            background: #fff;
            border: 1px solid #ddd;
            padding: 5px 10px;
            margin-bottom: 5px;
            border-radius: 4px;
        }
        .file-preview-thumb {
            width: 40px;
            height: 40px;
            object-fit: cover;
            margin-right: 10px;
            border-radius: 3px;
        }
    </style>';

    // Images Drop Zone
    echo '<div class="mb-3">';
    echo '<label class="form-label fw-bold">Add Images' . ($mode=='edit'?' (Append)':'') . '</label>';
    
    // The actual input (hidden)
    echo '<input type="file" class="d-none" name="log_images[]" id="log_images" multiple accept="image/*,.root">';
    
    // The Visual Drop Zone
    echo '<div id="drop_zone" class="upload-drop-zone">';
    echo '<i class="bi bi-cloud-upload" style="font-size: 2rem;"></i><br>'; // Bootstrap Icon if available, or text
    echo '<span class="fw-bold">Drag & Drop images here</span><br>';
    echo '<small>or click to browse</small>';
    echo '</div>';
    
    // Preview List
    echo '<div id="file_preview_list" class="mt-2"></div>';
    echo '</div>';

    // ROOT File Browser Section
    echo '<div class="mb-3">';
    echo '<label class="form-label fw-bold">ROOT File Plots</label>';

    // Upload ROOT File button + hidden input
    echo '<div class="mb-2">';
    echo '<input type="file" class="d-none" id="root_file_input" accept=".root">';
    echo '<button type="button" class="btn btn-sm btn-outline-success" id="root_upload_btn" onclick="document.getElementById(\'root_file_input\').click();">';
    echo '<span>📊</span> Upload ROOT File</button>';
    echo '<span id="root_upload_status" class="ms-2 small text-muted"></span>';
    echo '</div>';

    // ROOT File Browser Card (initially hidden)
    echo '<div id="root_browser_card" class="card mb-2" style="display:none;">';
    echo '<div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">';
    echo '<span id="root_browser_filename" class="fw-bold small"></span>';
    echo '<button type="button" class="btn-close btn-sm" onclick="document.getElementById(\'root_browser_card\').style.display=\'none\';"></button>';
    echo '</div>';
    echo '<div class="card-body p-2">';
    echo '<div id="root_tree_view" style="max-height:300px; overflow-y:auto; font-size:0.85rem;"></div>';
    echo '</div>';
    echo '</div>';

    // Selected Plots Area
    echo '<div id="root_selected_plots" class="mb-2"></div>';

    // Hidden input for form submission
    echo '<input type="hidden" name="root_plots" id="root_plots_hidden" value="' . hsc($data['root_plots'] ?? '') . '">';
    echo '</div>';

    // Content (Markdown Editor)
    echo '<div class="mb-3">';
    echo '<label for="log_content" class="form-label fw-bold">Content</label>';
    echo '<textarea class="form-control" id="log_content" name="log_content" rows="10" placeholder="Write your log here...">' . hsc($content) . '</textarea>';
    echo '</div>';
    
    echo '<div class="d-flex justify-content-between">';
    echo '<a href="' . wl($ID) . '" class="btn btn-secondary" onclick="window.onbeforeunload = null;">Cancel</a>';
    echo '<button type="submit" class="btn btn-primary" onclick="prepareSubmission()">' . $btn_text . '</button>';
    echo '</div>';
    
    echo '</form>';
    echo '</div></div>';
    
    // JSON & Init
    $json_tags = json_encode($tags);
    
    // Initial Tags
    $current_tags_js = '[]';
    if (!empty($data['tags'])) {
         $t_arr = explode(',', $data['tags']);
         $t_arr = array_map('trim', $t_arr);
         // Filter empty
         $t_arr = array_values(array_filter($t_arr));
         $current_tags_js = json_encode($t_arr);
    }

    // JS init values (JSON-encoded for safe embedding in <script>).
    $root_ns_js = json_encode($ns ?? getNS($ID));
    $sectok_js = json_encode(getSecurityToken());

    echo <<<EOT
<script>
        // --- EasyMDE & Form Logic ---
        var isDirty = false;
        document.getElementById("log_form").addEventListener("change", function() { isDirty = true; });
        document.getElementById("log_form").addEventListener("input", function() { isDirty = true; });
        
        window.onbeforeunload = function() {
            if (isDirty) return "Unsaved changes. Leave?";
        };

        window.easyMDE_editor = new EasyMDE({ 
            element: document.getElementById("log_content"),
            spellChecker: false,
            forceSync: true,
            autosave: { enabled: false }, 
            status: false,
            toolbar: ["bold", "italic", "heading", "|", "quote", "unordered-list", "ordered-list", "|", "link", "image", "|", "preview", "side-by-side", "fullscreen"]
        });
        
        window.easyMDE_editor.codemirror.on("change", function(){ isDirty = true; });

        window.prepareSubmission = function() {
            if (window.easyMDE_editor) {
                document.getElementById("log_content").value = window.easyMDE_editor.value();
            }
            window.onbeforeunload = null;
            isDirty = false;
        };
        
        document.getElementById("log_form").addEventListener("submit", function() {
            window.prepareSubmission();
        });

        function toggleNewCategory(select) {
            var input = document.getElementById("log_category_new");
            if (select.value === "___new___") {
                input.classList.remove("d-none");
                input.focus();
            } else {
                input.classList.add("d-none");
            }
        }
        
        // --- Tags Logic ---
        var existingTags = {$json_tags};
        var currentTags = {$current_tags_js};
        var tagInput = document.getElementById("log_tags_input");
        var tagsContainer = document.getElementById("tags_container");
        var hiddenInput = document.getElementById("log_tags_hidden");
        var suggestionsBox = document.getElementById("tags_suggestions");
        
        function updateHiddenInput() {
            hiddenInput.value = currentTags.join(", ");
            isDirty = true;
        }
        function renderChips() {
            var chips = tagsContainer.querySelectorAll(".tag-chip");
            chips.forEach(function(chip) { chip.remove(); });
            currentTags.forEach(function(tag) {
                var chip = document.createElement("span");
                chip.className = "tag-chip badge bg-primary me-1 d-flex align-items-center";
                chip.style.fontSize = "0.9em";
                chip.innerHTML = tag + " <span style=\'cursor:pointer; margin-left:5px; font-weight:bold;\' onclick=\'removeTag(\"" + tag + "\")\'>&times;</span>";
                tagsContainer.insertBefore(chip, tagInput);
            });
            updateHiddenInput(); 
        }
        function addTag(tag) {
            tag = tag.trim(); if (!tag) return;
            if (currentTags.includes(tag)) { tagInput.value = ""; return; }
            currentTags.push(tag); renderChips();
            tagInput.value = ""; suggestionsBox.style.display = "none";
        }
        window.removeTag = function(tag) {
            currentTags = currentTags.filter(function(t) { return t !== tag; });
            renderChips();
        };
        tagInput.addEventListener("keydown", function(e) {
            if (e.key === "Enter" || e.key === ",") {
                e.preventDefault(); addTag(this.value.replace(",", ""));
            } else if (e.key === "Backspace" && this.value === "" && currentTags.length > 0) {
                var lastTag = currentTags[currentTags.length - 1]; removeTag(lastTag); this.value = lastTag; e.preventDefault(); 
            }
        });
        tagInput.addEventListener("input", function() {
            var val = this.value.toLowerCase();
            if (!val) { suggestionsBox.style.display = "none"; return; }
            var matches = existingTags.filter(function(t) { return t.toLowerCase().includes(val); });
            if (matches.length > 0) {
                suggestionsBox.innerHTML = "";
                matches.forEach(function(match) {
                    var item = document.createElement("a");
                    item.className = "list-group-item list-group-item-action";
                    item.href = "#"; item.textContent = match;
                    item.onclick = function(e) { e.preventDefault(); addTag(match); };
                    suggestionsBox.appendChild(item);
                });
                suggestionsBox.style.top = (tagsContainer.offsetTop + tagsContainer.offsetHeight) + "px";
                suggestionsBox.style.left = tagsContainer.offsetLeft + "px";
                suggestionsBox.style.width = tagsContainer.offsetWidth + "px";
                suggestionsBox.style.display = "block";
            } else { suggestionsBox.style.display = "none"; }
        });
        tagInput.addEventListener("blur", function() { setTimeout(function() { suggestionsBox.style.display = "none"; }, 200); });
        renderChips(); // Init
        
        // --- Drag & Drop Image Logic ---
        var dropZone = document.getElementById("drop_zone");
        var fileInput = document.getElementById("log_images");
        var previewList = document.getElementById("file_preview_list");
        var dt = new DataTransfer(); // Holds the files
        
        // Handle Click
        dropZone.addEventListener("click", function() {
            fileInput.click();
        });
        
        // Handle File Select (via Click)
        fileInput.addEventListener("change", function() {
            for (var i = 0; i < this.files.length; i++) {
                dt.items.add(this.files[i]);
            }
            updateFiles();
        });
        
        // Handle Drag Over
        dropZone.addEventListener("dragover", function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add("drag-over");
        });
        
        dropZone.addEventListener("dragleave", function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove("drag-over");
        });
        
        // Handle Drop
        dropZone.addEventListener("drop", function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove("drag-over");

            var files = e.dataTransfer.files;
            for (var i = 0; i < files.length; i++) {
                if (files[i].type.startsWith("image/") || files[i].name.toLowerCase().endsWith(".root")) {
                    dt.items.add(files[i]);
                }
            }
            updateFiles();
        });
        
        function updateFiles() {
            fileInput.files = dt.files; // Sync input
            renderPreview();
            isDirty = true;
        }
        
        function renderPreview() {
            previewList.innerHTML = "";
            var files = dt.files;

            if (files.length === 0) return;

            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                var div = document.createElement("div");
                div.className = "file-preview-item";

                // For .root files, show file icon instead of thumbnail
                if (file.name.toLowerCase().endsWith(".root")) {
                    var icon = document.createElement("div");
                    icon.className = "file-preview-thumb d-flex align-items-center justify-content-center bg-light border";
                    icon.innerHTML = "<span style=\'font-size:18px;\'>📊</span>";
                    div.appendChild(icon);
                } else {
                    // Thumbnail for images
                    var img = document.createElement("img");
                    img.className = "file-preview-thumb";
                    img.file = file;
                    div.appendChild(img);

                    // Read File for Thumb (only for images)
                    var reader = new FileReader();
                    reader.onload = (function(aImg) { return function(e) { aImg.src = e.target.result; }; })(img);
                    reader.readAsDataURL(file);
                }

                // Name & Size
                var info = document.createElement("div");
                info.style.flexGrow = "1";
                info.innerHTML = "<strong>" + file.name + "</strong> <small class=\'text-muted\'>(" + (file.size/1024).toFixed(1) + " KB)</small>";
                div.appendChild(info);

                // Remove Button
                var btn = document.createElement("button");
                btn.className = "btn btn-sm btn-outline-danger";
                btn.innerHTML = "&times;";
                btn.type = "button"; // Prevent submit
                (function(index) {
                     btn.onclick = function(e) {
                         e.stopPropagation(); // Prevent dropZone click
                         dt.items.remove(index);
                         updateFiles();
                     };
                })(i);
                div.appendChild(btn);

                previewList.appendChild(div);
            }
        }

        // --- ROOT File Browser Logic ---
        var rootSelectedPlots = [];
        var rootFileMap = {}; // Maps filename -> {tempName, url}
        var rootNs = {$root_ns_js};

        // Initialize from existing data (edit mode)
        var existingRootPlots = document.getElementById('root_plots_hidden').value;
        if (existingRootPlots.trim()) {
            existingRootPlots.split(',').forEach(function(p) {
                p = p.trim();
                if (p) rootSelectedPlots.push(p);
            });
            renderSelectedPlots();
        }

        document.getElementById('root_file_input').addEventListener('change', function() {
            var file = this.files[0];
            if (!file || !file.name.toLowerCase().endsWith(".root")) return;

            var statusEl = document.getElementById('root_upload_status');
            statusEl.textContent = 'Uploading ' + file.name + '...';
            statusEl.className = 'ms-2 small text-info';

            var formData = new FormData();
            formData.append('root_file', file);
            formData.append('ns', rootNs);
            formData.append('sectok', {$sectok_js});

            // Determine upload URL
            var uploadUrl = window.location.pathname + '?do=upload_root_temp';

            fetch(uploadUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    statusEl.textContent = file.name + ' uploaded.';
                    statusEl.className = 'ms-2 small text-success';
                    rootFileMap[file.name] = {tempName: data.temp_name, url: data.url};

                    // Load JSROOT and browse the file
                    loadJSROOTAndBrowse(file.name, data.url);
                } else {
                    statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusEl.className = 'ms-2 small text-danger';
                }
            })
            .catch(function(err) {
                statusEl.textContent = 'Upload error: ' + err.message;
                statusEl.className = 'ms-2 small text-danger';
            });

            // Reset input so same file can be re-selected
            this.value = '';
        });

        function loadJSROOTAndBrowse(filename, url) {
            var browserCard = document.getElementById('root_browser_card');
            var filenameEl = document.getElementById('root_browser_filename');
            var treeView = document.getElementById('root_tree_view');

            filenameEl.textContent = filename;
            treeView.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading JSROOT...</div>';
            browserCard.style.display = 'block';

            // Load JSROOT if not loaded (use ES module for zstd support)
            if (typeof JSROOT === 'undefined') {
                import('https://root.cern/js/7.8.0/modules/main.mjs').then(function(module) {
                    window.JSROOT = module;
                    browseRootFile(filename, url, treeView);
                }).catch(function(err) {
                    treeView.innerHTML = '<div class="alert alert-danger py-1 small">Failed to load JSROOT library: ' + err + '</div>';
                });
            } else {
                browseRootFile(filename, url, treeView);
            }
        }

        function browseRootFile(filename, url, treeView) {
            treeView.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Reading ROOT file...</div>';

            // Fetch entire file as ArrayBuffer to avoid multi-range request issues
            fetch(url).then(function(resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.arrayBuffer();
            }).then(function(buf) {
                return JSROOT.openFile(buf, filename);
            }).then(function(file) {
                treeView.innerHTML = '';
                var keys = file.fKeys || [];
                renderKeys(file, keys, '', filename, treeView, 0);
            }).catch(function(err) {
                treeView.innerHTML = '<div class="alert alert-danger py-1 small">Error reading file: ' + err + '</div>';
            });
        }

        var plottableTypes = ['TH1', 'TH1F', 'TH1D', 'TH1I', 'TH1S', 'TH1C',
                              'TH2', 'TH2F', 'TH2D', 'TH2I', 'TH2S', 'TH2C',
                              'TH3', 'TH3F', 'TH3D', 'TH3I', 'TH3S', 'TH3C',
                              'TProfile', 'TProfile2D', 'TProfile3D',
                              'TGraph', 'TGraphErrors', 'TGraphAsymmErrors',
                              'TMultiGraph', 'TCanvas', 'TPad',
                              'TF1', 'TF2', 'TF3'];

        function isPlottable(className) {
            if (!className) return false;
            return plottableTypes.some(function(t) { return className === t || className.indexOf(t) === 0; });
        }

        function isDirectory(className) {
            if (!className) return false;
            return className === 'TDirectory' || className === 'TDirectoryFile';
        }

        function renderKeys(file, keys, basePath, rootFilename, container, depth) {
            if (!keys || keys.length === 0) {
                container.innerHTML += '<div class="text-muted small py-1" style="padding-left:' + (depth*16) + 'px;">(empty)</div>';
                return;
            }

            keys.forEach(function(key) {
                var keyName = key.fName;
                var keyClass = key.fClassName;
                var fullPath = basePath ? basePath + '/' + keyName : keyName;
                var div = document.createElement('div');
                div.style.paddingLeft = (depth * 16) + 'px';
                div.className = 'py-1 border-bottom';

                if (isDirectory(keyClass)) {
                    // Directory - expandable
                    var expanded = false;
                    var childContainer = document.createElement('div');
                    childContainer.style.display = 'none';

                    div.innerHTML = '<span class="text-muted" style="cursor:pointer;">📁 <strong>' + keyName + '</strong></span>';
                    div.style.cursor = 'pointer';
                    div.onclick = function(e) {
                        e.stopPropagation();
                        if (!expanded) {
                            expanded = true;
                            childContainer.style.display = 'block';
                            childContainer.innerHTML = '<div class="text-center py-1"><div class="spinner-border spinner-border-sm text-secondary" style="width:1rem;height:1rem;"></div></div>';
                            // Read the directory object to get its fKeys
                            file.readObject(fullPath).then(function(dirObj) {
                                childContainer.innerHTML = '';
                                var subKeys = dirObj.fKeys || [];
                                renderKeys(file, subKeys, fullPath, rootFilename, childContainer, depth + 1);
                            }).catch(function(err) {
                                childContainer.innerHTML = '<div class="text-danger small">Error: ' + err + '</div>';
                            });
                        } else {
                            childContainer.style.display = childContainer.style.display === 'none' ? 'block' : 'none';
                        }
                    };

                    container.appendChild(div);
                    container.appendChild(childContainer);
                } else if (isPlottable(keyClass)) {
                    // Plottable object
                    var plotKey = rootFilename + ':' + fullPath;
                    var isSelected = rootSelectedPlots.indexOf(plotKey) !== -1;

                    div.innerHTML = '<span style="cursor:pointer;" class="' + (isSelected ? 'text-success fw-bold' : 'text-primary') + '">📈 ' + keyName + ' <small class="text-muted">(' + (keyClass || '?') + ')</small>' + (isSelected ? ' ✓' : '') + '</span>';
                    div.style.cursor = 'pointer';
                    div.onclick = function(e) {
                        e.stopPropagation();
                        togglePlotSelection(plotKey);
                        // Update display
                        var span = div.querySelector('span');
                        var sel = rootSelectedPlots.indexOf(plotKey) !== -1;
                        span.className = sel ? 'text-success fw-bold' : 'text-primary';
                        span.style.cursor = 'pointer';
                        span.innerHTML = '📈 ' + keyName + ' <small class="text-muted">(' + (keyClass || '?') + ')</small>' + (sel ? ' ✓' : '');
                    };

                    container.appendChild(div);
                } else {
                    // Other object type - show but not clickable
                    div.innerHTML = '<span class="text-muted">⬜ ' + keyName + ' <small>(' + (keyClass || '?') + ')</small></span>';
                    container.appendChild(div);
                }
            });
        }

        function togglePlotSelection(plotKey) {
            var idx = rootSelectedPlots.indexOf(plotKey);
            if (idx === -1) {
                rootSelectedPlots.push(plotKey);
            } else {
                rootSelectedPlots.splice(idx, 1);
            }
            updateRootPlotsHidden();
            renderSelectedPlots();
            isDirty = true;
        }

        function updateRootPlotsHidden() {
            document.getElementById('root_plots_hidden').value = rootSelectedPlots.join(', ');
        }

        function renderSelectedPlots() {
            var container = document.getElementById('root_selected_plots');
            container.innerHTML = '';

            if (rootSelectedPlots.length === 0) return;

            var label = document.createElement('div');
            label.className = 'small text-muted mb-1';
            label.textContent = 'Selected plots (' + rootSelectedPlots.length + '):';
            container.appendChild(label);

            rootSelectedPlots.forEach(function(plotKey, i) {
                var badge = document.createElement('span');
                badge.className = 'badge bg-success me-1 mb-1';
                badge.style.fontSize = '0.8em';
                badge.innerHTML = plotKey + ' <span style="cursor:pointer;margin-left:4px;" onclick="removeRootPlot(' + i + ')">&times;</span>';
                container.appendChild(badge);
            });
        }

        window.removeRootPlot = function(index) {
            rootSelectedPlots.splice(index, 1);
            updateRootPlotsHidden();
            renderSelectedPlots();
            isDirty = true;
        };

    </script>
EOT;
}

function elog_render_newlog_form($ns, $categories, $tags) {
    elog_render_log_form($ns, $categories, $tags, 'new');
}

/**
 * Render attachments (images) for a specific log entry
 */
function elog_render_attachments($id, $allow_delete = false) {
    global $conf;
    
    $id_parts = explode(':', $id);
    $ns = implode(':', array_slice($id_parts, 0, -1));
    $page_name = end($id_parts); // e.g. "100"
    
    if (empty($ns)) return;
    
    $media_root = $conf['mediadir'];
    $ns_dir = utf8_encodeFN(str_replace(':', '/', $ns));
    $upload_dir = $media_root . '/' . $ns_dir;
    
    if (!is_dir($upload_dir)) return;
    
    $files = scandir($upload_dir);
    $images = [];
    
    // Find files specifically for this page ID: "100_..."
    $prefix = $page_name . '_';
    $len = strlen($prefix);
    
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        if (substr($file, 0, $len) === $prefix) {
            $images[] = $file;
        }
    }
    
    if (!empty($images)) {
        echo '<div class="mt-4">';
        echo '<h5 class="border-bottom pb-2 mb-3">Attachments</h5>';
        echo '<div class="d-flex flex-column gap-4">';
        foreach ($images as $img) {
            // Construct URL. DokuWiki fetch.php?media=ns:filename
            $media_id = $ns . ':' . $img;
            // Use full URL for source to ensure best quality, rely on CSS for resizing
            $full_url = ml($media_id);

            // Check file extension
            $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));
            $is_root_file = ($ext === 'root');

            echo '<div class="card shadow-sm">';

            if ($is_root_file) {
                // Render ROOT file as download card
                echo '<div class="card-body p-3 text-center bg-light">';
                echo '<div style="font-size:3rem;">📊</div>';
                echo '<div class="mt-2"><strong>' . hsc($img) . '</strong></div>';
                echo '<a href="' . $full_url . '" class="btn btn-sm btn-outline-primary mt-2" download>Download ROOT File</a>';
                echo '</div>';
            } else {
                // Render image
                echo '<div class="card-body p-2 text-center bg-light">';

                // Image linked to raw file
                echo '<a href="' . $full_url . '" target="_blank">';
                // img-fluid class in Bootstrap provides max-width: 100%; height: auto;
                echo '<img src="' . $full_url . '" alt="' . hsc($img) . '" class="img-fluid rounded">';
                echo '</a>';

                echo '</div>';
            }

            // Footer with Filename Link & Delete
            echo '<div class="card-footer bg-white d-flex justify-content-between align-items-center">';
            echo '<div class="text-truncate" style="max-width: 70%;"><a href="' . $full_url . '" target="_blank" title="'.hsc($img).'">' . hsc($img) . '</a></div>';

            if ($allow_delete) {
                $chk_id = 'del_'.md5($img);
                echo '<div class="form-check form-switch m-0" title="Delete Image?">';
                echo '<input class="form-check-input" type="checkbox" name="delete_images[]" value="'.hsc($img).'" id="'.$chk_id.'" onchange="this.closest(\'.card\').style.opacity = this.checked ? \'0.4\' : \'1\'; this.closest(\'.card\').style.filter = this.checked ? \'grayscale(100%)\' : \'none\';">';
                echo '<label class="form-check-label text-danger" for="'.$chk_id.'"><i class="bi bi-trash"></i></label>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
}

/**
 * Render attached images inline (compact, for Full mode in list view)
 */
function elog_render_attachments_inline($id) {
    global $conf;

    $id_parts = explode(':', $id);
    $ns = implode(':', array_slice($id_parts, 0, -1));
    $page_name = end($id_parts);

    if (empty($ns)) return;

    $media_root = $conf['mediadir'];
    $ns_dir = utf8_encodeFN(str_replace(':', '/', $ns));
    $upload_dir = $media_root . '/' . $ns_dir;

    if (!is_dir($upload_dir)) return;

    $files = scandir($upload_dir);
    $images = [];
    $prefix = $page_name . '_';
    $len = strlen($prefix);

    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        if (substr($file, 0, $len) === $prefix) {
            $images[] = $file;
        }
    }

    if (empty($images)) return;

    echo '<div class="mt-3 pt-3 border-top">';
    echo '<div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-2">';
    foreach ($images as $img) {
        // Skip .root files in inline view (no thumbnails)
        $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));
        if ($ext === 'root') continue;

        $media_id = $ns . ':' . $img;
        $thumb_url = ml($media_id, ['w' => 300, 'h' => 300]);
        $full_url = ml($media_id);

        echo '<div class="col">';
        echo '<a href="' . $full_url . '" target="_blank">';
        echo '<img src="' . $thumb_url . '" alt="' . hsc($img) . '" class="img-fluid rounded shadow-sm" style="width:100%; height:120px; object-fit:cover;">';
        echo '</a>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
}

/**
 * Render a gallery of all images in the current namespace
 */
function elog_render_gallery($ns) {
    global $conf;
    
    if (empty($ns)) return;
    
    $media_root = $conf['mediadir'];
    $ns_dir = utf8_encodeFN(str_replace(':', '/', $ns));
    $upload_dir = $media_root . '/' . $ns_dir;
    
    if (!is_dir($upload_dir)) {
        echo '<div class="alert alert-info">No images found in this logbook.</div>';
        return;
    }
    
    $files = scandir($upload_dir);
    $images = [];
    
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        // Check extension
        $ext = strtolower(substr(strrchr($file, '.'), 1));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $images[] = $file;
        }
    }
    
    // Sort by filemtime desc (newest first)
    usort($images, function($a, $b) use ($upload_dir) {
        return filemtime($upload_dir . '/' . $b) - filemtime($upload_dir . '/' . $a);
    });
    
    echo '<div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-6 g-3">';
    
    foreach ($images as $img) {
        // Parse ID from filename: "123_filename.jpg"
        $parts = explode('_', $img, 2);
        if (count($parts) < 2 || !is_numeric($parts[0])) continue; // Skip if not matching format
        
        $page_id_num = $parts[0];
        $page_id = $ns . ':' . $page_id_num;
        
        $media_id = $ns . ':' . $img;
        $src = ml($media_id, ['w' => 300, 'h' => 300]); // Larger thumbnail
        
        echo '<div class="col">';
        echo '<div class="card h-100 shadow-sm">';
        echo '<a href="' . wl($page_id) . '">';
        echo '<img src="' . $src . '" class="card-img-top" alt="' . hsc($img) . '" style="height: 150px; object-fit: cover;">';
        echo '</a>';
        echo '<div class="card-footer p-1 text-center small">';
        echo '<a href="' . wl($page_id) . '" class="text-decoration-none">Log #' . $page_id_num . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    
    if (empty($images)) {
        echo '<div class="alert alert-info">No images found in this logbook.</div>';
    }
}

/**
 * Get available logbooks (top-level namespaces)
 * Excludes system namespaces like wiki, playground
 */
function elog_get_logbooks() {
    global $conf;

    // Per-request cache (safe because PHP requests are isolated).
    static $cache = null;
    if ($cache !== null) return $cache;
    
    $dir = $conf['datadir'];
    $logbooks = array();
    
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            if (!is_dir($dir . '/' . $file)) continue;
            
            // Exclude system namespaces
            if (in_array($file, ['wiki', 'playground', 'help', 'archive'])) continue;
            
            // ACL Check: Skip if user doesn't have READ permission on this namespace
            if (auth_quickaclcheck($file . ':*') < AUTH_READ) continue;
            
            // Check if it's a valid logbook (e.g. valid name pattern?)
            // For now, accept all other directories
            
            $logbooks[] = $file;
        }
    }
    
    // Sort by modification time (most recent first)
    usort($logbooks, function($a, $b) use ($dir) {
        return filemtime($dir . '/' . $b) - filemtime($dir . '/' . $a);
    });
    
    $cache = $logbooks;
    return $cache;
}

/**
 * Handle Page Deletion (Admin)
 */
function elog_handle_delete_page() {
    global $INFO, $ID;
    
    // Only admins
    if (!$INFO['isadmin']) return;
    
    $do = $_REQUEST['do'] ?? '';
    if ($do == 'delete_page') {
        if (!checkSecurityToken()) return;
        
        // Delete by saving empty content
        saveWikiText($ID, '', 'Deleted by Admin');
        
        msg("Page '$ID' deleted.", 1);
        
        // Redirect logic
        global $conf;
        $ns = getNS($ID);
        // If we deleted a page in a namespace, go to that namespace's start
        if ($ns) {
            send_redirect(wl($ns . ':' . $conf['start']));
        } else {
            send_redirect(wl($conf['start']));
        }
    }
}

/**
 * Handle Logbook Administration (Add/Delete)
 */
function elog_handle_logbook_admin() {
    global $conf, $INFO, $ID;
    
    // Only admins can do this
    if (!$INFO['isadmin']) return;
    
    $do = $_REQUEST['do'] ?? '';
    
    if ($do == 'add_logbook') {
        if (!checkSecurityToken()) return;
        
        $name = trim($_REQUEST['new_logbook_name'] ?? '');
        $name = cleanID($name);
        
        if (empty($name)) {
            msg("Logbook name cannot be empty.", -1);
            return;
        }
        
        // Create directory in data/pages
        $page_dir = $conf['datadir'] . '/' . utf8_encodeFN($name);
        if (is_dir($page_dir)) {
            msg("Logbook '$name' already exists.", -1);
            return;
        }
        
        if (!mkdir($page_dir, $conf['dmode'], true)) {
            msg("Failed to create logbook '$name'. Check permissions.", -1);
            return;
        }
        
        // Create a start page for the logbook so it appears legitimate
        // $start_page = $page_dir . '/' . utf8_encodeFN($conf['start']) . '.txt';
        // io_saveFile($start_page, "====== " . strtoupper($name) . " ======\n\nWelcome to the " . $name . " logbook.");
        // Actually, let's just create the directory. DokuWiki will see it if we scan directories.
        // But our elog_get_logbooks checks for is_dir.
        // It's better to populate it with a start page.
        saveWikiText($name . ':' . $conf['start'], "====== " . strtoupper($name) . " ======\n\nWelcome to the " . $name . " logbook.", 'Created new logbook');
        
        msg("Logbook '$name' created successfully.", 1);
        send_redirect(wl($conf['start']));
        
    } elseif ($do == 'delete_logbook') {
        if (!checkSecurityToken()) return;
        
        $target = trim($_REQUEST['logbook_name'] ?? '');
        $target = cleanID($target);
        if (empty($target)) return;
        
        // Safety: Prevent deleting system folders or root
        if (in_array($target, ['wiki', 'playground', 'data', 'conf', 'bin', 'inc'])) {
            msg("Cannot delete system namespace '$target'.", -1);
            return;
        }

        // Delete pages directory
        $page_dir = $conf['datadir'] . '/' . utf8_encodeFN($target);
        if (is_dir($page_dir)) {
            // Recursive delete using DokuWiki's io_rmdir
            if (io_rmdir($page_dir, true)) {
                msg("Logbook '$target' deleted.", 1);
                
                // Cleanup media if possible? Best effort.
                $media_dir = $conf['mediadir'] . '/' . utf8_encodeFN($target);
                if (is_dir($media_dir)) io_rmdir($media_dir, true);
                
                // Cleanup meta?
                // $meta_dir = $conf['metadir'] . '/' . utf8_encodeFN($target);
                // if (is_dir($meta_dir)) io_rmdir($meta_dir, true);
                
                send_redirect(wl($conf['start']));
            } else {
                msg("Failed to delete logbook '$target'.", -1);
            }
        } else {
             msg("Logbook '$target' not found.", -1);
        }
    }
}

/**
 * Render ROOT plots for a log entry using JSROOT
 */
function elog_render_root_plots($id) {
    global $conf;

    $data = elog_get_entry_data($id);
    $root_plots_str = $data['root_plots'] ?? '';
    if (empty($root_plots_str)) return;

    // Parse root_plots: "file.root:path, file.root:path2"
    $plots = array_filter(array_map('trim', explode(',', $root_plots_str)));
    if (empty($plots)) return;

    // Find .root files in media for this entry
    $id_parts = explode(':', $id);
    $ns = implode(':', array_slice($id_parts, 0, -1));
    $page_name = end($id_parts);

    $media_root = $conf['mediadir'];
    $ns_dir = utf8_encodeFN(str_replace(':', '/', $ns));
    $upload_dir = $media_root . '/' . $ns_dir;

    // Build map: original filename -> media URL
    $root_file_urls = [];
    if (is_dir($upload_dir)) {
        $files = scandir($upload_dir);
        $prefix = $page_name . '_';
        $len = strlen($prefix);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            if (substr($file, 0, $len) !== $prefix) continue;
            if (!preg_match('/\.root$/i', $file)) continue;

            // Extract original filename: {id}_{time}_{idx}_{origname}
            // Split into at most 4 parts
            $parts = explode('_', $file, 4);
            $orig_name = isset($parts[3]) ? $parts[3] : $file;

            $media_id = $ns . ':' . $file;
            $root_file_urls[$orig_name] = ml($media_id);
        }
    }

    if (empty($root_file_urls)) return;

    echo '<div class="mt-4">';
    echo '<h5 class="border-bottom pb-2 mb-3">ROOT Plots</h5>';

    // Load JSROOT via ES module (supports zstd compression)
    echo '<script type="module">';
    echo 'import * as JSROOT from "https://root.cern/js/7.8.0/modules/main.mjs";';
    echo 'window.JSROOT = JSROOT;';
    echo 'window.dispatchEvent(new Event("jsroot_ready"));';
    echo '</script>';

    foreach ($plots as $idx => $plot) {
        // Parse "filename.root:object/path"
        $colon_pos = strpos($plot, ':');
        if ($colon_pos === false) continue;

        $filename = substr($plot, 0, $colon_pos);
        $obj_path = substr($plot, $colon_pos + 1);

        if (empty($filename) || empty($obj_path)) continue;

        // Find URL for this filename
        $url = null;
        foreach ($root_file_urls as $orig => $u) {
            if ($orig === $filename || strpos($orig, strtolower(str_replace('.', '', $filename))) !== false) {
                $url = $u;
                break;
            }
        }

        if (!$url) {
            // Try exact match with cleanID
            $clean_filename = cleanID($filename);
            foreach ($root_file_urls as $orig => $u) {
                if (strpos($orig, $clean_filename) !== false) {
                    $url = $u;
                    break;
                }
            }
        }

        if (!$url) continue;

        $div_id = 'root_plot_' . $idx;

        echo '<div class="card mb-3 shadow-sm">';
        echo '<div class="card-header bg-light py-2">';
        echo '<span class="small">📊 <strong>' . hsc($filename) . '</strong> : <code>' . hsc($obj_path) . '</code></span>';
        echo '</div>';
        echo '<div class="card-body p-2">';
        echo '<div id="' . $div_id . '" style="min-height:400px; width:100%;">';
        echo '<div class="text-center py-5"><div class="spinner-border text-primary"></div><br><small class="text-muted">Loading plot...</small></div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<script>';
        echo '(function() {';
        echo '  var divId = ' . json_encode($div_id) . ';';
        echo '  var url = ' . json_encode($url) . ';';
        echo '  var objPath = ' . json_encode($obj_path) . ';';
        echo '  ';
        echo '  function doRender() {';
        echo '    fetch(url).then(function(r) { if (!r.ok) throw new Error("HTTP "+r.status); return r.arrayBuffer(); })';
        echo '    .then(function(buf) { return window.JSROOT.openFile(buf, url); })';
        echo '    .then(function(file) { return file.readObject(objPath); })';
        echo '    .then(function(obj) {';
        echo '      document.getElementById(divId).innerHTML = "";';
        echo '      return window.JSROOT.draw(divId, obj);';
        echo '    }).catch(function(err) {';
        echo '      document.getElementById(divId).innerHTML = \'<div class="alert alert-danger m-3">Failed to load plot: \' + err + \'</div>\';';
        echo '    });';
        echo '  }';
        echo '  ';
        echo '  if (window.JSROOT && window.JSROOT.openFile) {';
        echo '    doRender();';
        echo '  } else {';
        echo '    window.addEventListener("jsroot_ready", doRender);';
        echo '  }';
        echo '})();';
        echo '</script>';
    }

    echo '</div>';
}

/**
 * Render a gallery of all ROOT plots across entries in a namespace
 */
function elog_render_root_gallery($ns) {
    global $conf;

    if (empty($ns)) return;

    $dir = $conf['datadir'] . '/' . utf8_encodeFN(str_replace(':', '/', $ns));
    if (!is_dir($dir)) {
        echo '<div class="alert alert-info">No entries found.</div>';
        return;
    }

    // Scan all entries for RootPlots metadata
    $entries = [];
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..' || substr($file, -4) != '.txt') continue;
        $name = substr($file, 0, -4);
        if (!is_numeric($name)) continue;

        $id = $ns . ':' . $name;
        $data = elog_get_entry_data($id);
        if (empty($data['root_plots'])) continue;

        $meta = p_get_metadata($id);
        $date = $meta['date']['created'] ?? filemtime($dir . '/' . $file);

        $plots = array_filter(array_map('trim', explode(',', $data['root_plots'])));
        $entries[] = [
            'id' => $name,
            'full_id' => $id,
            'subject' => $data['subject'],
            'date' => $date,
            'plots' => $plots
        ];
    }

    // Sort by date descending
    usort($entries, function($a, $b) { return $b['date'] - $a['date']; });

    if (empty($entries)) {
        echo '<div class="alert alert-info">No ROOT plots found in this logbook.</div>';
        return;
    }

    // Build file URL map per entry
    $media_root = $conf['mediadir'];
    $ns_dir_path = utf8_encodeFN(str_replace(':', '/', $ns));
    $upload_dir = $media_root . '/' . $ns_dir_path;

    // JSROOT module
    echo '<script type="module">';
    echo 'import * as JSROOT from "https://root.cern/js/7.8.0/modules/main.mjs";';
    echo 'window.JSROOT = JSROOT;';
    echo 'window.dispatchEvent(new Event("jsroot_ready"));';
    echo '</script>';

    $plot_idx = 0;
    foreach ($entries as $entry) {
        // Find .root files for this entry
        $root_urls = [];
        if (is_dir($upload_dir)) {
            $mfiles = scandir($upload_dir);
            $prefix = $entry['id'] . '_';
            $plen = strlen($prefix);
            foreach ($mfiles as $mf) {
                if (substr($mf, 0, $plen) !== $prefix) continue;
                if (!preg_match('/\.root$/i', $mf)) continue;
                $parts = explode('_', $mf, 4);
                $orig = isset($parts[3]) ? $parts[3] : $mf;
                $root_urls[$orig] = ml($ns . ':' . $mf);
            }
        }
        if (empty($root_urls)) continue;

        echo '<div class="card mb-3 shadow-sm">';
        echo '<div class="card-header bg-light py-2">';
        echo '<a href="' . wl($entry['full_id']) . '" class="text-decoration-none fw-bold">#' . hsc($entry['id']) . '</a>';
        echo ' — ' . hsc($entry['subject']);
        echo ' <small class="text-muted ms-2">' . date('Y-m-d H:i', $entry['date']) . '</small>';
        echo '</div>';
        echo '<div class="card-body p-2">';

        foreach ($entry['plots'] as $plot) {
            $colon_pos = strpos($plot, ':');
            if ($colon_pos === false) continue;
            $filename = substr($plot, 0, $colon_pos);
            $obj_path = substr($plot, $colon_pos + 1);
            if (empty($filename) || empty($obj_path)) continue;

            // Match URL
            $url = null;
            $clean_filename = cleanID($filename);
            foreach ($root_urls as $orig => $u) {
                if ($orig === $filename || strpos($orig, $clean_filename) !== false) {
                    $url = $u;
                    break;
                }
            }
            if (!$url) continue;

            $div_id = 'rg_plot_' . $plot_idx;
            echo '<div class="mb-2">';
            echo '<div class="small text-muted mb-1"><code>' . hsc($filename) . ':' . hsc($obj_path) . '</code></div>';
            echo '<div id="' . $div_id . '" style="min-height:350px; width:100%;">';
            echo '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Loading...</div>';
            echo '</div>';
            echo '</div>';

            echo '<script>';
            echo '(function() {';
            echo '  var divId = ' . json_encode($div_id) . ';';
            echo '  var url = ' . json_encode($url) . ';';
            echo '  var objPath = ' . json_encode($obj_path) . ';';
            echo '  function doRender() {';
            echo '    fetch(url).then(function(r) { if (!r.ok) throw new Error("HTTP "+r.status); return r.arrayBuffer(); })';
            echo '    .then(function(buf) { return window.JSROOT.openFile(buf, url); })';
            echo '    .then(function(file) { return file.readObject(objPath); })';
            echo '    .then(function(obj) {';
            echo '      document.getElementById(divId).innerHTML = "";';
            echo '      return window.JSROOT.draw(divId, obj);';
            echo '    }).catch(function(err) {';
            echo '      document.getElementById(divId).innerHTML = \'<div class="alert alert-danger py-1 small">Failed: \' + err + \'</div>\';';
            echo '    });';
            echo '  }';
            echo '  if (window.JSROOT && window.JSROOT.openFile) { doRender(); }';
            echo '  else { window.addEventListener("jsroot_ready", doRender); }';
            echo '})();';
            echo '</script>';

            $plot_idx++;
        }

        echo '</div></div>';
    }
}
