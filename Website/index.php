<?php?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>IWD2 ICA</title>

    	<!-- Global stylesheet -->
    	<link rel="stylesheet" href="/assets/css/global.css">
</head>

<body>

<!-- Top Navigation Bar, this is to be consistent across all pages, dropdown menu under "New Analysis"-->
<header class="site_header">
	<nav class="navigation_bar" role="site_navigation" aria-label="Main navigation">

		<!-- Main Navigation Links -->
		<ul class="navigation_links" role="list">
			<li><a href="index.php" class="navigation_link active">Home</a></li>
			
			<!-- New Analysis Dropdown menu -->
			<li class="new_analysis_dropdown">
				<a href="/pages/fetch.php" class="navigation_link">
					New Analysis <i class="fa_solid fa_chevron_down fa_xs"></i>
				</a>
				<ul class="dropdown_menu" role="list">
					<li><a href="/pages/fetch.php">1. Fetch Sequences</a></li>
					<li><a href="/pages/analysis.php">2. Conservation Analysis</a></li>
					<li><a href="/pages/motifs.php">3. Motif Scanning</a></li>
					<li><a href="/pages/extras.php">4. Extra Analyses</a></li>
				</ul>
			</li>

			<li><a href="/pages/example.php" class="navigation_link">Example Dataset</a></li>
			<li><a href="/pages/revisit.php" class="navigation_link">My Sessions</a></li>
			<li><a href="/pages/help.php" class="navigation_link">Help</a></li>
			<li><a href="/pages/about.php" class="navigation_link">About</a></li>
			<li><a href="/pages/credits.php" class="navigation_link">Credits</a></li>
			<li><a href="https://github.com/WesleyKoc/public_html.git" class="navigation_link nav_icon_link">Github</a></li>
		</ul></nav>
</header>

<!-- Pipeline Overview -->
 <section class="pipeline_section container">

    <h2 class="section_title">How it works</h2>
    <p class="section_subtitle">
        A four-step pipeline from sequence retrieval to biological interpretation.
    </p>

    <ol class="pipeline_steps" role="list">

        <li class="pipeline_card">
            <span class="step_number">01</span>
            <h3 class="step_title">Fetch Sequences</h3>
            <p class="step_description">
                Define a protein family and a taxonomic group. This program queries <br>
                NCBI Entrez to retrieve all matching protein sequences and stores <br>
                them for analysis.
            </p>
            <a href="/pages/fetch.php" class="step_link"> Go to Fetch </a>
        </li>
<br>
        <li class="pipeline_card">
            <span class="step_number">02</span>
            <h3 class="step_title">Conservation Analysis</h3>
            <p class="step_description">
                Sequences are aligned with ClustalOmega and conservation is <br>
                scored position-by-position. Results are visualised as interactive <br>
                plots and pairwise identity heatmaps.
            </p>
            <a href="/pages/analysis.php" class="step_link">
                Go to Analysis 
            </a>
        </li>
<br>
        <li class="pipeline_card">
            <span class="step_number">03</span>
            <h3 class="step_title">Motif Scanning</h3>
            <p class="step_description">
                Each sequence is scanned against the PROSITE pattern database using <br>
                EMBOSS <code>patmatmotifs</code>. Functional domains are mapped <br>
                and summarised across the full sequence set.
            </p>
            <a href="/pages/motifs.php" class="step_link">
                Go to Motifs
            </a>
        </li>
<br>
        <li class="pipeline_card">
            <span class="step_number">04</span>
            <h3 class="step_title">Extra Analyses</h3>
            <p class="step_description">
                Contains: Physicochemical properties, predicted secondary structure, <br>
                3D structure cross-references, UniProt annotations, and <br>
                pathway context
            </p>
            <a href="/pages/extras.php" class="step_link">
                Go to Extras
            </a>
        </li>

    </ol>

</section>

<!-- Example Dataset -->
 <section class="example_callout">
    <div class="container example_callout_inner">
        <div class="example_text">
            <h2 class="example_title">Here's an example dataset you can start with peeps!</h2>
            <p class="example_description">
                Feel free to look at our pre-loaded example dataset: <br>
				Glucose-6-phosphatase proteins from <em>Aves</em> (birds) <br>
				You can take a look at every analysis step with real data, <br>
				before running your own query.
            </p>
            <a href="/pages/example.php" class="btn btn-light">
                Explore the Example
            </a>
        </div>
		<br>
        <!-- Placeholder: replace with a real output thumbnail once generated -->
        <div class="example-image-placeholder" aria-hidden="true">
            <span>Conservation plot preview</span>
        </div>

    </div>
</section>

<!-- Site Footer -->
<footer class="site_footer">
    <div class="container footer_inner">

        <p class="footer_brand">
            My IWD2 ICA
        </p>

        <nav class="footer_nav" aria-label="Footer navigation">
            <a href="index.php">Home</a>
            <a href="/pages/fetch.php">New Analysis</a>
            <a href="/pages/example.php">Example</a>
            <a href="/pages/revisit.php">My Sessions</a>
            <a href="/pages/help.php">Help</a>
            <a href="/pages/about.php">About</a>
            <a href="/pages/credits.php">Credits</a>
            <a href="https://github.com/WesleyKoc/public_html.git"
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

</body>
</html>
