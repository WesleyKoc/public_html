<?php?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>IWD2 ICA</title>

    	<!-- Global stylesheet -->
    	<link rel="stylesheet" href="assets/css/global.css">
</head>

<body>

<!-- Top Navigation Bar, this is to be consistent across all pages, dropdown menu under "New Analysis"-->
<header class="site_header">
	<nav class="navbar" role="site_navigation" aria-label="Main navigation">

		<!-- Main Navigation Links -->
		<ul class="nav-links" role="list">
			<li><a href="index.php" class="nav-link active">Home</a></li>
			
			<!-- New Analysis Dropdown menu -->
			<li class="new-analysis-dropdown">
				<a href="pages/fetch.php" class="nav-link">
					New Analysis <i class="fa-solid fa-chevron-down fa-xs"></i>
				</a>
				<ul class="dropdown-menu" role="list">
					<li><a href="pages/fetch.php">1. Fetch Sequences</a></li>
					<li><a href="pages/analysis.php">2. Conservation Analysis</a></li>
					<li><a href="pages/motifs.php">3. Motif Scanning</a></li>
					<li><a href="pages/extras.php">4. Extra Analyses</a></li>
				</ul>
			</li>

			<li><a href="pages/example.php" class="nav-link">Example Dataset</a></li>
			<li><a href="pages/revisit.php" class="nav-link">My Sessions</a></li>
			<li><a href="pages/help.php" class="nav-link">Help</a></li>
			<li><a href="pages/about.php" class="nav-link">About</a></li>
			<li><a href="pages/credits.php" class="nav-link">Credits</a></li>

			<!-- GitHub icon link -->
			<li>
				<a href="https://github.com/YOUR_USERNAME/phyloseq"
				class="nav-link nav-icon-link"
                   		target="_blank"
                   		rel="noopener noreferrer"
                   		aria-label="GitHub repository">
                    			<i class="fa-brands fa-github"></i>
                		</a>
            		</li>
	</nav>
</header>


</body>
</html>
