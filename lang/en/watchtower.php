<?php
/**
 * English (en) — Watchtower module strings.
 *
 * Source-of-truth locale. Every other lang/<code>/watchtower.php may omit keys;
 * missing keys fall back to the value here (see includes/i18n.php).
 *
 * Watchtower is a cross-module attention dashboard. Covers the header, the
 * dashboard chrome, the per-module card labels/metrics/attention lines rendered
 * by inline JS, and the full help guide.
 *
 * NOT covered here (data pulled live from other modules): ticket subjects,
 * event titles, article titles, service names, etc.
 */
return [
    'title' => 'Watchtower',

    'nav' => [
        'dashboard' => 'Dashboard',
        'help'      => 'Help',
    ],

    'dashboard' => [
        'heading'      => 'Attention Overview',
        'refresh'      => 'Refresh',
        'updated'      => 'Updated {time}',
    ],

    // Per-module card names shown in the card header (links to each module).
    'cards' => [
        'morning_checks' => 'Morning Checks',
        'tickets'        => 'Tickets',
        'changes'        => 'Changes',
        'calendar'       => 'Calendar',
        'service_status' => 'Service Status',
        'contracts'      => 'Contracts',
        'knowledge'      => 'Knowledge',
        'assets'         => 'Assets',
        'tasks'          => 'Tasks',
        'workflows'      => 'Workflows',
    ],

    // Workflows card. The engine swallows its own errors by design (so a broken
    // workflow can't break the ticket save that triggered it) — which means a
    // failing workflow is silent. This card is what breaks that silence.
    'workflows' => [
        'all_clear'     => 'No workflow failures',
        'failed'        => '<span class="wt-attention-bold">{count}</span> workflow run(s) failed in the last 24h',
        'aborted'       => '<span class="wt-attention-bold">{count}</span> run(s) aborted by loop protection in the last 24h',
        'dead_webhooks' => '<span class="wt-attention-bold">{count}</span> webhook(s) gave up retrying — the message never arrived',
        'failures'      => '{count} failure(s)',
    ],

    // Morning Checks card.
    'mc' => [
        'metric_done' => 'Done',
        'metric_ok'   => 'OK',
        'metric_warn' => 'Warn',
        'metric_fail' => 'Fail',
        'not_started'      => 'Checks not started today',
        'pending'          => '{count} checks still pending',
        'failed'           => '{count} check(s) failed',
        'warnings'         => '{count} check(s) with warnings',
        'all_passing'      => 'All checks completed and passing',
    ],

    // Tickets card.
    'tickets' => [
        'metric_open'   => 'Open',
        'metric_new'    => 'New',
        'metric_active' => 'Active',
        'metric_hold'   => 'Hold',
        'urgent_high'   => '<span class="wt-attention-bold">{count}</span> urgent/high priority tickets',
        'unassigned'    => '<span class="wt-attention-bold">{count}</span> unassigned tickets',
        'paused_one'    => '<span class="wt-attention-bold">{count}</span> ticket paused over {hours}h (SLA clock stopped)',
        'paused_many'   => '<span class="wt-attention-bold">{count}</span> tickets paused over {hours}h (SLA clock stopped)',
        'all_clear'     => 'No urgent items',
    ],

    // Changes card.
    'changes' => [
        'metric_next_7d' => 'Next 7d',
        'metric_active'  => 'Active',
        'metric_pending' => 'Pending',
        'awaiting'       => '<span class="wt-attention-bold">{count}</span> change(s) awaiting approval',
        'in_progress'    => '{count} change(s) in progress now',
        'scheduled'      => '{count} change(s) scheduled this week',
        'all_clear'      => 'No upcoming changes',
    ],

    // Calendar card.
    'calendar' => [
        'metric_today' => 'Today',
        'metric_week'  => 'This week',
        'all_day'      => 'All day',
        'no_events'    => 'No events today',
    ],

    // Service Status card.
    'service' => [
        'all_operational' => 'All systems operational',
        'active_incidents' => '<span class="wt-attention-bold">{count}</span> active incident(s)',
    ],

    // Contracts card.
    'contracts' => [
        'metric_30d'     => '30 days',
        'metric_90d'     => '90 days',
        'metric_notices' => 'Notices',
        'expiring'       => '<span class="wt-attention-bold">{count}</span> contract(s) expiring within 30 days',
        'notices'        => '<span class="wt-attention-bold">{count}</span> notice period(s) approaching',
        'all_clear'      => 'No contracts requiring attention',
    ],

    // Knowledge card.
    'knowledge' => [
        'overdue'         => '<span class="wt-attention-bold">{count}</span> article(s) overdue for review',
        'published_week'  => 'Published this week',
        'up_to_date'      => 'Knowledge base up to date',
    ],

    // Assets card.
    'assets' => [
        'metric_total'    => 'Total',
        'metric_offline'  => 'Offline',
        'metric_warranty' => 'Warranty',
        'warranty'        => '<span class="wt-attention-bold">{count}</span> asset(s) with warranty expired or expiring within {days} days',
        'offline'         => '<span class="wt-attention-bold">{count}</span> asset(s) not seen in 7+ days',
        'all_active'      => 'All assets recently active',
    ],

    // Tasks card.
    'tasks' => [
        'metric_todo'   => 'To Do',
        'metric_active' => 'Active',
        'overdue'       => '<span class="wt-attention-bold">{count}</span> overdue task(s)',
        'due_today'     => '<span class="wt-attention-bold">{count}</span> due today',
        'all_clear'     => 'No overdue tasks',
    ],

    // Help guide.
    'help' => [
        'page_title'   => 'Watchtower Guide',
        'sidebar_label' => 'Guide',
        'hero_title'   => 'Watchtower guide',
        'hero_subtitle' => 'A unified attention dashboard showing actionable items from every module at a single glance.',

        'nav_overview'  => 'Overview',
        'nav_layout'    => 'The dashboard layout',
        'nav_dots'      => 'Understanding status dots',
        'nav_cards'     => 'Module cards explained',
        'nav_refresh'   => 'Auto-refresh',
        'nav_tips'      => 'Quick tips',

        // Section 1 — Overview
        's1_title' => 'Overview',
        's1_intro' => 'Watchtower is your single pane of glass for IT operations. Instead of opening each module individually to check for urgent items, Watchtower pulls the most important information from every module into one dashboard. At a glance you can see what needs attention, what is running smoothly, and where to focus your time.',
        's1_feat1_title' => 'Attention board',
        's1_feat1_desc'  => 'See what needs your focus across all modules in one place. Morning checks, tickets, changes, calendar events, service status, contracts, knowledge articles, and assets are all summarised on a single screen.',
        's1_feat2_title' => 'Colour-coded status',
        's1_feat2_desc'  => 'Every module card displays a green, amber, or red status dot for instant triage. You can tell at a glance which areas are healthy, which need attention, and which require immediate action.',
        's1_feat3_title' => 'Auto-refresh',
        's1_feat3_desc'  => 'The dashboard automatically refreshes every 5 minutes, so the information stays current without any manual action. Leave Watchtower open and it keeps itself up to date in the background.',
        's1_feat4_title' => 'Click-through',
        's1_feat4_desc'  => 'Jump directly into any module from its card. Each module name is a clickable link that takes you straight to the relevant area, so you can act on issues without searching for the right page.',

        // Section 2 — Dashboard layout
        's2_title' => 'The dashboard layout',
        's2_p1' => 'The Watchtower dashboard uses a responsive 3-column grid of module cards. On smaller screens the grid adapts to 2 columns or a single column, so it works on any device. Above the grid is the title bar with a refresh button and an "Updated" timestamp showing when data was last fetched.',
        's2_p2' => 'Each card in the grid follows a consistent structure so you can scan them quickly:',
        's2_diagram_name'   => 'Module Name',
        's2_diagram_open'   => 'OPEN',
        's2_diagram_active' => 'ACTIVE',
        's2_diagram_hold'   => 'HOLD',
        's2_diagram_clear'  => 'All clear — no urgent items',
        's2_field_icon'    => '<strong>Coloured icon</strong> &mdash; a small square icon in the module\'s theme colour (teal for Morning Checks, blue for Tickets, etc.) so you can identify each card instantly.',
        's2_field_name'    => '<strong>Module name</strong> &mdash; a clickable link that navigates directly to that module. Click to jump straight in and take action.',
        's2_field_dot'     => '<strong>Status dot</strong> &mdash; a green, amber, or red dot in the top-right corner showing the overall urgency level for that module.',
        's2_field_metrics' => '<strong>Key metrics</strong> &mdash; large numbers summarising the most important counts (e.g. open tickets, checks completed, contracts expiring).',
        's2_field_attention' => '<strong>Attention items</strong> &mdash; colour-coded message rows highlighting what specifically needs your attention within that module.',
        's2_tip' => 'The card layout is designed for scanning, not deep analysis. Use Watchtower to identify which modules need your attention, then click through to the module itself for full details.',

        // Section 3 — Status dots
        's3_title' => 'Understanding status dots',
        's3_intro' => 'Every module card displays a status dot in its header. This dot provides an instant visual indicator of whether that area of your IT operations needs attention. The colour is determined automatically based on the data returned from each module.',
        's3_green_label' => 'Green',
        's3_green_desc'  => 'Everything is fine. No action needed. The module is in a healthy state with no outstanding issues or items requiring attention.',
        's3_green_examples' => '<strong>Examples:</strong> All morning checks passing, no urgent tickets, all systems operational, no contracts expiring soon.',
        's3_amber_label' => 'Amber',
        's3_amber_desc'  => 'Something needs attention but is not critical. There are items you should review when you get a chance, but nothing is on fire.',
        's3_amber_examples' => '<strong>Examples:</strong> Checks with warnings, unassigned tickets, changes awaiting approval, contracts expiring within 90 days.',
        's3_red_label' => 'Red',
        's3_red_desc'  => 'Urgent items require immediate action. Something has failed, is overdue, or is critically impacted and needs to be addressed right away.',
        's3_red_examples' => '<strong>Examples:</strong> Morning checks not started or failed, urgent/high priority tickets, major service outages, contracts expiring within 30 days.',
        's3_tip' => 'Think of the dots like a traffic light. Green means go about your day, amber means review when possible, and red means stop what you are doing and investigate. The goal is to keep all dots green.',

        // Section 4 — Module cards explained
        's4_title' => 'Module cards explained',
        's4_intro' => 'Watchtower monitors eight modules. Each card is tailored to show the most relevant information for that area. Here is what each card displays and what triggers its status dot colour.',
        's4_mc_title'    => 'Morning Checks',
        's4_mc_desc'     => 'Shows completion progress (e.g. 8/10 done) plus counts of OK, Warning, and Fail results. Attention items flag when checks have not been started or when any have failed.',
        's4_mc_triggers' => '<strong>Red:</strong> Checks not started today, or any checks failed. <strong>Amber:</strong> Checks incomplete or warnings present. <strong>Green:</strong> All checks completed and passing.',
        's4_tk_title'    => 'Tickets',
        's4_tk_desc'     => 'Displays the total open count broken down into New, Active, and On Hold. Attention items highlight urgent/high priority tickets and any that are unassigned.',
        's4_tk_triggers' => '<strong>Red:</strong> Urgent or high priority tickets exist. <strong>Amber:</strong> Unassigned tickets present. <strong>Green:</strong> No urgent items or unassigned tickets.',
        's4_ch_title'    => 'Changes',
        's4_ch_desc'     => 'Shows the number of changes scheduled in the next 7 days, how many are currently in progress, and how many are pending approval. Attention items call out unapproved and active changes.',
        's4_ch_triggers' => '<strong>Amber:</strong> Changes awaiting approval. <strong>Green:</strong> No unapproved changes.',
        's4_cal_title'    => 'Calendar',
        's4_cal_desc'     => 'Displays the number of events today and this week. If there are events today, they are listed with their times (or "All day" for all-day events).',
        's4_cal_triggers' => '<strong>Amber:</strong> Events scheduled for today. <strong>Green:</strong> No events today.',
        's4_ss_title'    => 'Service Status',
        's4_ss_desc'     => 'Shows the count of active incidents and lists affected services with their impact level badges (Major Outage, Partial Outage, Degraded, Maintenance). When everything is healthy, a green "All systems operational" banner appears.',
        's4_ss_triggers' => '<strong>Red:</strong> Major or partial outage on any service. <strong>Amber:</strong> Degraded or maintenance status. <strong>Green:</strong> All systems operational.',
        's4_ct_title'    => 'Contracts',
        's4_ct_desc'     => 'Displays contracts expiring within 30 days, within 90 days, and notice periods approaching. Attention items warn about imminent expirations and upcoming notice deadlines.',
        's4_ct_triggers' => '<strong>Red:</strong> Contracts expiring within 30 days. <strong>Amber:</strong> Contracts expiring within 90 days or notice periods approaching. <strong>Green:</strong> No contracts requiring attention.',
        's4_kb_title'    => 'Knowledge',
        's4_kb_desc'     => 'Shows the number of articles overdue for review and lists recently published articles from this week. When no reviews are overdue and the knowledge base is current, the card shows an all-clear message.',
        's4_kb_triggers' => '<strong>Amber:</strong> Articles overdue for review. <strong>Green:</strong> Knowledge base up to date.',
        's4_as_title'    => 'Assets',
        's4_as_desc'     => 'Displays the total number of tracked assets and how many have not been seen in 7 or more days. This helps identify devices that may be offline, decommissioned, or lost.',
        's4_as_triggers' => '<strong>Amber:</strong> Assets not seen in 7+ days. <strong>Green:</strong> All assets recently active.',

        // Section 5 — Auto-refresh
        's5_title' => 'Auto-refresh and manual refresh',
        's5_intro' => 'Watchtower is designed to be a passive monitoring tool that you can leave open in a browser tab throughout the day. The dashboard keeps itself current through automatic refresh cycles.',
        's5_step1' => '<strong>Automatic refresh</strong> &mdash; the dashboard fetches fresh data from all modules every 5 minutes. You do not need to reload the page or click anything; the cards and status dots update silently in the background.',
        's5_step2' => '<strong>Manual refresh</strong> &mdash; click the <strong>Refresh</strong> button in the top-right corner to fetch the latest data immediately. The button icon spins while the request is in progress, confirming that new data is being loaded.',
        's5_step3' => '<strong>Updated timestamp</strong> &mdash; next to the refresh button, a timestamp shows the last time data was fetched (e.g. "Updated 09:15"). This tells you exactly how current the displayed information is.',
        's5_tip' => 'Keep Watchtower open in a dedicated browser tab for passive monitoring. The 5-minute refresh cycle means you always have a near-real-time view of your IT operations without needing to manually check each module.',

        // Section 6 — Quick tips
        's6_title' => 'Quick tips',
        's6_tip1_title' => 'Start your day here',
        's6_tip1_desc'  => 'Open Watchtower first thing each morning for a quick operational overview. In seconds you can see if morning checks are done, whether any tickets are urgent, and if all services are healthy.',
        's6_tip2_title' => 'Red dots first',
        's6_tip2_desc'  => 'Address red status dots before anything else. These indicate urgent items that need immediate attention &mdash; failed checks, high-priority tickets, or service outages that are actively impacting users.',
        's6_tip3_title' => 'Click to jump in',
        's6_tip3_desc'  => 'Click any module name on a card to navigate straight to that module. No need to use the main menu or waffle navigation &mdash; Watchtower acts as a direct shortcut to wherever attention is needed.',
        's6_tip4_title' => 'Hit Refresh for the latest',
        's6_tip4_desc'  => 'While the dashboard auto-refreshes every 5 minutes, you can click the Refresh button any time you want the very latest data. Useful after resolving an issue to confirm the status dot has changed.',
        's6_tip5_title' => 'Use it in team meetings',
        's6_tip5_desc'  => 'Project Watchtower onto a screen during stand-ups or operational review meetings. The colour-coded dots make it easy to discuss which areas need attention and assign ownership of amber or red items.',
        's6_tip6_title' => 'Green means all clear',
        's6_tip6_desc'  => 'When every dot on the dashboard is green, your IT operations are in good shape. No urgent tickets, no failed checks, no expiring contracts, and all services operational. That is the goal.',
    ],
];
