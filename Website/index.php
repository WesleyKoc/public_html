<?php
/**
 * index.php: ALIHS Landing Page
 *
 * This is the front door -> the first thing people see when they hit the site.
 * I wanted something clean but not boring, with a clear explanation of what
 * the pipeline does without overwhelming anyone. The dropdown nav took a few
 * tries to get right on mobile but I think it's okay now.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALIHS | A Little Intelligent Homology Searcher</title>
    
    <!--
    Took Libre Baskerville and Source Sans 3 from Google Fonts cuz I like NCBI format.
    I think it just looks more professional
    -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <!-- Font Awesome for the GitHub icon and chevron down on the dropdown -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Global stylesheet -> handles all the common stuff like spacing, colours, nav -->
    <link rel="stylesheet" href="assets/css/global.css">
</head>

<body>

<!--
    Top Navigation Bar
    This gets reused across every page so the site feels consistent.
    The dropdown under "New Analysis" was a pain to get working on touch screens
    -> eventually settled on a pure CSS solution with a little JS for mobile.
    Code adapted from: https://www.digitala11y.com/navigation-role/
		       https://www.a11y-collective.com/blog/aria-landmark-roles/
-->
<header class="site_header">
    <nav class="navi_bar" role="navigation" aria-label="Main navigation">

        <!-- Site logo -> tried a few variations before landing on this colour split -->
        <a href="index.php" class="navi_logo" aria-label="ALiHS home">
            <span class="logo_colour">AL</span><span class="logo_accent">i</span><span class="logo_colour">HS</span>
        </a>

        <!-- 
	 Main nav links unordered list
	 <ul> and <ol> are already native list elements, so role="list" is redundant, but just for legacy help
	 Code adapted from: https://accessibility.asu.edu/articles/aria
	 -->
        <ul class="navi_links" role="list">

            <li><a href="index.php" class="navi_link active">Home</a></li>

            <!--
                "New Analysis" dropdown
                This holds the four pipeline steps. I originally had them as separate
                links in the main bar but it got too crowded, so dropdown it is.
            -->
            <li class="navi_dropdown">
                <a href="pages/fetch.php" class="navi_link">
                    New Analysis <i class="fa-solid fa-chevron-down fa-xs"></i>
                </a>
                <ul class="dropdown_menu" role="list">
                    <li><a href="pages/fetch.php">Fetch Sequences</a></li>
                    <li><a href="pages/analysis.php">Conservation Analysis</a></li>
                    <li><a href="pages/motifs.php">Motif Scanning</a></li>
                    <li><a href="pages/extras.php">Extra Analyses</a></li>
                </ul>
            </li>

            <li><a href="pages/example.php" class="navi_link">Example Dataset</a></li>
            <li><a href="pages/revisit.php" class="navi_link">My Sessions</a></li>
            <li><a href="pages/help.php" class="navi_link">Help</a></li>
            <li><a href="pages/about.php" class="navi_link">About</a></li>
            <li><a href="pages/credits.php" class="navi_link">Credits</a></li>

            <!-- 
	     GitHub icon link -> opens in a new tab 
	     rel="noopener noreferrer" was used with target="_blank" to deal with security issues
	     Code adapted from: https://css-tricks.com/use-rel-noopener/
	    -->
            <li>
                <a href="https://github.com/WesleyKoc/public_html/tree/main/Website"
                   class="navi_link navi_icon_link"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="GitHub repository">
                    <i class="fa-brands fa-github"></i>
                </a>
            </li>
        </ul>

        <!--
            Hamburger toggle for mobile.
            The JS for this lives at the bottom of the page -> I probably could have
            put it in main.js but it's such a small thing I left it inline.
	    Code adapted from: https://stackoverflow.com/questions/73257448/drop-down-menu-not-showing-with-aria
        -->
        <button class="navi_hamburger" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>

    </nav>
</header>

<!--
    Intro section
    This is the intro bit at the top. I wanted something that explains what the
    tool does without sounding too technical. The background colour is just the
    NCBI colour scheme -> hopefully it's not too distracting.
-->
<section class="intro" style="background-color: #f0f4f5;">
    <div class="intro_inner container">

        <p class="intro_eyebrow">Protein Sequence Analysis</p>

        <h1 class="intro_title">
            Explore conservation<br>
            <em>across the tree of life</em>
        </h1>

        <p class="intro_subtitle">
            Retrieve protein families from any taxonomic group, align them,
            scan for known motifs, and uncover the biological story written
            in sequence variation.
        </p>

    </div>
</section>

<!--
    Pipeline overview
    Four cards showing the main steps. I originally had these as a numbered list
    with hyphens and all caps but it looked too rigid -> settled on this cleaner
    layout with the step number in a circle and plain english section names.
    The links go directly to each page, but they assume a job_id exists ->
    that part could probably be handled better, but for the landing page it's fine.
-->
<section class="pipeline_section container">

    <h2 class="section_title">How it works</h2>

    <ol class="pipeline_steps" role="list">

        <li class="pipeline_card">
            <span class="step_number">01</span>
            <h3 class="step_title">Fetch Sequences</h3>
            <p class="step_describe">
                Define a protein family and a taxonomic group. PhyloSeq queries
                NCBI Entrez to retrieve all matching protein sequences and stores
                them for analysis.
            </p>
            <a href="pages/fetch.php" class="step_link">
                Go to Fetch
            </a>
        </li>

        <li class="pipeline_card">
            <span class="step_number">02</span>
            <h3 class="step_title">Conservation Analysis</h3>
            <p class="step_describe">
                Sequences are aligned with ClustalOmega and conservation is
                scored position-by-position. Results are visualised as interactive
                plots and pairwise identity heatmaps.
            </p>
            <a href="pages/analysis.php" class="step_link">
                Go to Analysis
            </a>
        </li>

        <li class="pipeline_card">
            <span class="step_number">03</span>
            <h3 class="step_title">Motif Scanning</h3>
            <p class="step_describe">
                Each sequence is scanned against the PROSITE pattern database using
                EMBOSS <code>patmatmotifs</code>. Functional domains are mapped
                and summarised across the full sequence set.
            </p>
            <a href="pages/motifs.php" class="step_link">
                Go to Motifs
            </a>
        </li>

        <li class="pipeline_card">
            <span class="step_number">04</span>
            <h3 class="step_title">Extra Analyses</h3>
            <p class="step_describe">
                Physicochemical properties, predicted secondary structure,
                3D structure cross-references, UniProt annotations, and
                pathway context — all in one place.
            </p>
            <a href="pages/extras.php" class="step_link">
                Go to Extras
            </a>
        </li>

    </ol>

</section>

<!--
    Example dataset callout
    I wanted a quick way for people to try things out without having to think
    about what to search for -> the birds/glucose-6-phosphatase example seems
    to work well. The placeholder icon on the right is just there for visual
    balance, I'll replace it with an actual chart preview at some point.
-->
<section class="example_callout" style="background-color: #112e51; color: #ffffff;">
    <div class="container example_callout_inner">

        <div class="example_text">
            <h2 class="example_title">Not sure where to start?</h2>
            <p class="example_description">
                Be my guest, feel free to look at our pre-loaded example dataset: <br>
                Glucose-6-phosphatase proteins from <em>Aves</em> (birds) <br>
                Go ahead m8, take a look at every analysis step with real data, <br>
                before running your own query. Hope this helps :)
            </p>
            <a href="pages/example.php" class="button button_light">
                Explore the Example
            </a>
        </div>

        <!--
            This is just a placeholder for now -> I wanted to put a small preview of
            the conservation plot here but the API calls got messy so I left it as
            a font awesome icon. Maybe one day I'll come back and make it dynamic.
        -->
        <div class="example_image" aria-hidden="true">
            <img src="results/example/plotcon.png" 
                 alt="Conservation plot for glucose-6-phosphatase in Aves"
                 style="max-width: 100%; height: auto; border-radius: 8px;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        </div>

    </div>
</section>

<!--
    Footer
    Same footer across all pages -> keeps the credits and tool acknowledgements
    visible without being intrusive. The GitHub link points to the repo.
-->
<footer class="site_footer">
    <div class="container footer_inner">

        <p class="footer_brand">
            <strong>ALIHS</strong> | A Little Intelligent Homology Searcher
        </p>

        <nav class="footer_navi" aria-label="Footer navigation">
            <a href="index.php">Home</a>
            <a href="pages/fetch.php">New Analysis</a>
            <a href="pages/example.php">Example</a>
            <a href="pages/revisit.php">My Sessions</a>
            <a href="pages/help.php">Help</a>
            <a href="pages/about.php">About</a>
            <a href="pages/credits.php">Credits</a>
            <a href="https://github.com/WesleyKoc/public_html/tree/main/Website"
               target="_blank"
               rel="noopener noreferrer">GitHub</a>
        </nav>

        <p class="footer_note">
            Built using
            <a href="https://biopython.org/" target="_blank" rel="noopener noreferrer">Biopython</a>,
            <a href="https://emboss.sourceforge.net/" target="_blank" rel="noopener noreferrer">EMBOSS</a>,
            <a href="http://www.clustal.org/omega/" target="_blank" rel="noopener noreferrer">ClustalOmega</a>,
            and <a href="https://prosite.expasy.org/" target="_blank" rel="noopener noreferrer">PROSITE</a>.
            &nbsp;|&nbsp; Data sourced from
            <a href="https://www.ncbi.nlm.nih.gov/" target="_blank" rel="noopener noreferrer">NCBI</a>
            and <a href="https://www.uniprot.org/" target="_blank" rel="noopener noreferrer">UniProt</a>.
        </p>

    </div>
</footer>


<!--
    JavaScript
    main.js handles the dropdown and some other site-wide behaviours.
    The inline script below is just for the hamburger menu toggle -> I kept it
    separate because it felt wrong to force the whole main.js to load just for that.
-->
<script src="assets/js/main.js"></script>

<script>
    /**
     * Mobile navigation toggle
     * This toggles the 'navi_open' class on the nav links when the hamburger
     * is clicked. I had to remember to update the aria-expanded attribute too
     * -> accessibility is one of those things I keep forgetting until I run
     * the page through a screen reader and realise I missed it.
     * Code adapted from: https://blog.logrocket.com/create-responsive-mobile-menu-css-without-javascript/
     */
    const hamburger = document.querySelector('.navi_hamburger');
    const navLinks  = document.querySelector('.navi_links');

    if (hamburger && navLinks) {
        hamburger.addEventListener('click', function () {
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            navLinks.classList.toggle('navi_open');
        });
    }
</script>

</body>
</html>
