<?php 

// ---------------------------------
// AUTHOR:        Joel Richard, Smithsonian Libraries (richardjm@si.edu)
// 
// DATE CREATED:  January 14, 2014 
// 
// NOTES:         * Based on the fullresolutionimageextractor.pl script written by Kalev Leetaru
//                * Comments labeled TODO should be addressed before putting into production
//                * This parses potentially huge XML files and loads them into memory. This may
//                  not be the best way to go about doing this. We should REALLY be using an event-based
//                  XML parser. 
// 
// REQUIRES:      ImageMagick's "convert" (http://www.imagemagick.org/)
//                Kakadu Software's "kdu_buffered_expand" (http://www.kakadusoftware.com/)
//                CJPEG (http://jpegclub.org/cjpeg/), as well as "wget" and "zip"
// 
// USAGE:         php flickr_upload.php IDENTIFIER_TO_PROCESS
// 
// CHANGES:       2014-01-14: JMR - Created
//               (YYYY-MM-DD: ZZZ - Notes about what changed)
// ----------------------------------

// Just in case. :) 
ini_set('memory_limit', '-1');
require_once("phpflickr/phpFlickr.php");

//  Check our arguments
if (!isset($argv[1])) {
	print "No identifier supplied!\n\n".usage();
	exit(1);
}
$identifier = $argv[1];

$token  = 'TOKEN_GOES_HERE'; // Hardcoded because I'm lazy. See https://www.flickr.com/services/api/flickr.auth.getToken.html
$key    = 'API_KEY_GOES_HERE'; // https://www.flickr.com/services/apps/create/apply/
$secret = 'API_SECRET_GOES_HERE';

// =================================
// Declare some global variables
// =================================
$scandata = array();
$images = array();
$pages = array();
$pagecount = 0;
$image_id = 0;
$have_image = false;
$block_type = '';
$text = '';
// =================================
// Initialize our connection to Flickr
// =================================

$keys = _get_keys();
$flickr = new phpFlickr($key, $secret);
$flickr->setToken($token);
$flickr->auth("write");

flickr_upload($flickr, $identifier, $kdx);

exit(0);


function flickr_upload($flickr, $identifier, $kdx) {
	if (!$identifier) {
		print "No identifier supplied!\n";
		return;
	}

	// =================================
	// Is this identifier already at Flickr?
	// =================================
	$criteria = array('tags' => 'bookid'.$identifier);
	$results = $flickr->photos_search($criteria);
		
	if ($results['total'] > 0) {
		print "EXISTS AT FLICKR: $identifier\n";
		return;
	}	
	
	print "UPLOADING: $identifier\n";
	
	// =================================
	// Make our directory structure for images and stuff
	// =================================
	// Get the path from where we are running this script
	// TODO This may need a chdir() call instead of getcwd() for implementation at Internet Archive
	$dirname = getcwd();
	$cachedir = "$dirname/cache/upload";
	
	// Get our file to process...
	if (!file_exists($cachedir)) {
		mkdir("$cachedir");
	}
	if (!file_exists("$cachedir/$identifier")) {
		mkdir("$cachedir/$identifier");
	}
	if (!file_exists("$cachedir/$identifier/images")) {
		mkdir("$cachedir/$identifier/images");
	}
	if (!file_exists($cachedir.'/'.$identifier.'/upload_imgs')) {
		mkdir($cachedir.'/'.$identifier.'/upload_imgs');
	}
	
	// =================================
	// Get the metadata and ABBYY OCR files
	// =================================
	
	// first access its file list, determine whether it has the Abbyy file and the page images files, and then download the right files...
	$abbyy_url = '';
	$jpg_url = '';
	$jp2_url = '';
	$tif_url = '';
	$scandata_url = '';
	$image_url = '';
	$ext = '';
	// BEGIN - Local modification - JMR
	// If you aren't using a local or attached filesystem to get the files, 
	// set the $local_path to an empty string and the check for an existing 
	// file will fail (in four places below) and the script will download 
	// from the web instead.
	$letter = substr($identifier,0,1);
	$local_path = "/Volumes/sil/biodiversity/$letter/$identifier";
	// END - Local modification

	try {
		$file_list = file_get_contents("http://archive.org/download/".$identifier."/".$identifier."_files.xml");
	} catch (Exception $e) {
		print "    ERROR: Unable to get _files.xml for $identifier\n";
		return;
	}
	
	if ($file_list) {
		$file_xml = simplexml_load_string($file_list);
	} else {
		print "    Couldn't get ".$identifier."_files.xml\n";
		cleanup($cachedir, $identifier);
		return;
	}
	
	foreach ($file_xml->file as $f) {
		$att = 'name';
		$fn = (string)$f->attributes()->$att;
		if (strpos($fn, '_abbyy.gz'))     { $abbyy_url = $fn; }
		if (strpos($fn, '_jpg.zip'))      { $jpg_url = $fn; $ext = 'jpg'; }
		if (strpos($fn, '_jp2.zip'))      { $jp2_url = $fn; $ext = 'jp2'; }
		if (strpos($fn, '_tif.zip'))      { $tif_url = $fn; $ext = 'tif'; }
		if (strpos($fn, '_scandata.xml')) { $scandata_url = $fn;}
		if (strpos($fn, '_meta.xml'))     { $meta_url = $fn;}
	}
		
	if ($jpg_url != '') {
		$image_url = $jpg_url;
	} else if ($jp2_url != '') {
		$image_url = $jp2_url;
	} else if ($tif_url != '') {
		$image_url = $tif_url;
	}
	unset($file_xml);
	
	if (!$abbyy_url || !$image_url || !$scandata_url ) {
		print "    Could not get abbyy, image, and/or scandata files.\n";
		cleanup($cachedir, $identifier);
		return;
	}
	
	// create the filename for the images... NOTE - can't just drop all after "_" as there are filenames like "PMLP09691-morley_1597"...
	// JMR - This is confusing, but it works. I think the variables could be named better.
	$image_in_file = preg_replace("/_(jpg|jp2|tif)\.zip/", '', $image_url);
	$image_internal_url = preg_replace("/\.zip/", '', $image_url);
	
	// and download to disk and unpack...
	print "    Getting ABBYY, SCANDATA, and META files...\n";
	
	if (!file_exists("$cachedir/$identifier/abbyy")) {
		print "    Downloading Abbyy...\n";
		if (file_exists($local_path.'/'.$abbyy_url)) {
			copy($local_path.'/'.$abbyy_url, "$cachedir/$identifier/abbyy.gz");
		} else {
			$cmd = "wget -q --no-check-certificate 'http://archive.org/download/$identifier/$abbyy_url' -O '$cachedir/$identifier/abbyy.gz'";
			print "$cmd\n";
			`$cmd`;
		}
		`gunzip -qf '$cachedir/$identifier/abbyy.gz'`;
	}
	if (!file_exists("$cachedir/$identifier/scandata.xml")) {
		print "    Downloading Scandata...\n";
		if (file_exists($local_path.'/'.$scandata_url)) {
			copy($local_path.'/'.$scandata_url, "$cachedir/$identifier/scandata.xml");
		} else {
			$cmd = "wget -q --no-check-certificate 'http://archive.org/download/$identifier/$scandata_url' -O '$cachedir/$identifier/scandata.xml'";
			print "$cmd\n";
			`$cmd`;
		}
	}
	if (!file_exists("$cachedir/$identifier/meta.xml")) {
		print "    Downloading Meta...\n";
		if (file_exists($local_path.'/'.$meta_url)) {
			copy($local_path.'/'.$meta_url, "$cachedir/$identifier/meta.xml");
		} else {
			$cmd = "wget -q --no-check-certificate 'http://archive.org/download/$identifier/$meta_url' -O '$cachedir/$identifier/meta.xml'";
			print "$cmd\n";
			`$cmd`;
		}
	}
	
	// verify that we were able to successfully download both of them.
	if (filesize("$cachedir/$identifier/abbyy") < 1000 
			|| filesize("$cachedir/$identifier/scandata.xml") < 1000
			|| filesize("$cachedir/$identifier/meta.xml") < 10) {	
		print "    Could not get ABBYY, SCANDATA, and/or META files...\n";
		cleanup($cachedir, $identifier);
		return;
	}
	
	// =================================
	// Process the scandata file
	// =================================
	$droppage = array();
	$skippage = array();
	
	print "    Processing SCANDATA file ...";
	
	$scandata_xml = @simplexml_load_string(file_get_contents("$cachedir/$identifier/scandata.xml"));
	
	if (!$scandata_xml) {
		print "Scandata could not be parsed. Skipping.\n";
		cleanup($cachedir, $identifier);
		return;
	}
	
	global $scandata;
	
	// Count the number of good pages
	$pagecount = 0;
	foreach ($scandata_xml->pageData->page as $p) {
		if ($p->addToAccessFormats != 'false') {
			$pagecount++;
		}
	}
	
	// read in the scandata to identify which pages we care about
	$pageindex = 0;
	foreach ($scandata_xml->pageData->page as $p) {
		$att = 'leafNum';
		$leafnum = (string)$p->attributes()->$att;
	
		$page = array();
		// We don't care about covers or anything in the first four or last four pages.
		$page['skip'] = ($p->pageType == 'Cover' || $pageindex < 4 || $pageindex > ($pagecount-4));
		$page['leafnum'] = (string)$p->attributes()->$att;
		if ($p->addToAccessFormats == 'false') {
			$page['drop'] = true;
			$page['pagenum'] = "N/A";
		} else {
			$page['drop'] = false;
			$page['pagenum'] = $pageindex++;
		}
		$scandata[$leafnum] = $page;
	}
	// Free that memory!!
	unset($scandata_xml);
	print " Found $pagecount pages.\n";
	
	// =================================
	// Process the ABBYY file to identify images on pages
	// =================================
	
	print "    Processing ABBYY file...";
	
	global $images;
	global $pages;
	global $pagecount;
	global $image_id;
	global $have_image;
	global $block_type;
	global $text;
	
	if (file_exists("$cachedir/$identifier/abbyy")) {
		$fh = fopen("$cachedir/$identifier/abbyy", "r");
		parse_abbyy_xml($fh); // this populates the $images array
		fclose($fh);
	} else {
		print "    File not found: "."$cachedir/$identifier/abbyy"."\n";
		return;
	}
	
	if (count($images) < 4 || count(array_keys($pages)) < 3) {
		// there aren't enough images in this book to bother with, so skip it and move on...
		print "\n    Not enough images to upload to flickr: $identifier, Pages:".count(array_keys($pages)).", Good Images:".count($images)."\n";
		cleanup($cachedir, $identifier);
		return;
	}
	
	// =================================
	// Extract and crop the images
	// =================================
	
	print " Found ".count($images)." images.\n";
	// If we reach this point, there are enough images in this book to make it worth our while to process it, so NOW download its images...
	// Make a decision based on the number of pages with images, if it is less than 50, then fetch via IA's API, otherwise download the entire images ZIP file...
	$img_source = '';
	if (count($images) > 50) {
		$img_source = 'local'; // indicates imagezip is onsite...
		if (!file_exists("$cachedir/$identifier/images.zip")) {
			print "    Getting Images ZIP file ...\n";
			if (file_exists($local_path.'/'.$image_url)) {
				copy($local_path.'/'.$image_url, "$cachedir/$identifier/images.zip");
			} else {
				$cmd = "wget -q --no-check-certificate 'http://archive.org/download/$identifier/$image_url' -O '$cachedir/$identifier/images.zip'";
				`$cmd`;			
			}
		}
		if (filesize("$cachedir/$identifier/images.zip") < 10000) {
			print "    Images file too small. There must be a problem.\n";
			cleanup($cachedir, $identifier);
			return;
		}
	} else {
		// indicates images should be fetched ondemand...
		$img_source = 'download';
	} 
	
	print "    Cropping images...\n";
	// Now we process each identified image to get the page image from the ZIP file and crop it down to just the image derivative.
	foreach ($images as $i) {
		$pg = sprintf("%04d",$i['leaf_num']);
		$fname = $image_in_file.'_'.$pg.'.'.$ext;
		$cmd = '';	
		// We cache the file locally. If it's not there, go get it.
		if (!file_exists("$cachedir/$identifier/images/$fname")) {
			if ($img_source == 'local') {
				// If we are getting it from a local file, just unzip the file we are interested in
				$cmd = "unzip -q -j -d '$cachedir/$identifier/images/' '$cachedir/$identifier/images.zip' '$image_internal_url/$fname'";
			} else {
				// If we are getting it from the internet, use wget to get the file.
				$cmd = "wget -q --no-check-certificate \"http://archive.org/download/$identifier/$image_url/$image_internal_url/".$image_in_file."_".$pg.'.'.$ext."\" -O '$cachedir/$identifier/images/$fname'";
			}
			`$cmd`;
		}
		
		// If we're working with JP2, we use Kakadu to do the conversion
		$cmd = '';	
		if ($ext == 'jp2') {
			// TODO Switch this to kdu and TEST these calls.
			// $cmd = "./kdu_buffered_expand -i $cachedir/$identifier/images/$fname -o $cachedir/$identifier/images/kduconvert.ppm -num_threads 0 -int_region \"{$i['t'],$i['l']},{$i['height'],$i['width']}\" > /dev/null 2>&1";
			// `$cmd`;
			// $cmd = "./cjpeg -outfile $cachedir/$identifier/upload_imgs/$i['img_filename'] $cachedir/$identifier/images/kduconvert.ppm > /dev/null 2>&1";
			// `$cmd`;
			
			// TODO Kakadu is misbehaving on my computer. Using imagemagick instead
			$cmd = "convert -limit thread 1 -crop ".$i['width']."x".$i['height']."+".$i['l']."+".$i['t']." '$cachedir/$identifier/images/$fname' '".$cachedir."/".$identifier."/upload_imgs/".$i['img_filename']."' > /dev/null 2>&1";
			
		} elseif ($ext == 'jpg' || $ext == 'tif') {
			// JPEG images should use the imagemagick convert command
			$cmd = "convert -limit thread 1 -crop ".$i['width']."x".$i['height']."+".$i['l']."+".$i['t']." '$cachedir/$identifier/images/$fname' '".$cachedir."/".$identifier."/upload_imgs/".$i['img_filename']."' > /dev/null 2>&1";
		}
		if (!file_exists($cachedir."/".$identifier."/upload_imgs/".$i['img_filename'])) {
			print "    Creating ".$i['img_filename']."...\n";
			// print "$cmd\n";
			`$cmd`;
		}
	}
	
	// =================================
	// Upload to flickr!
	// =================================
	
	$meta_xml = simplexml_load_file("$cachedir/$identifier/meta.xml");
	
	print "    Uploading to Flickr ...\n";
	
	$count = 1;
	$max = count($images);
	
	foreach ($images as $i) {
		// Build the Title
		$title = 'Image from page '.$i['pagenum'].' of "'.$meta_xml->title.'"';
		$year = '';
		$decade = '';
		$century = '';
		$contributor = '';
		$contributor_url = '';
		$sponsor = '';
		$sponsor_url = '';
		if (isset($meta_xml->year)) {
			$title .= ' ('.$meta_xml->year.')';
			$year = $meta_xml->year;
			$century = substr($year, 0, 2) . '00';
			$decade = substr($year, 0, 3) . '0';

		} elseif (isset($meta_xml->date)) { 
			$title .= ' ('.$meta_xml->date.')';
			$year = $meta_xml->date;
			$century = substr($year, 0, 2) . '00';
			$decade = substr($year, 0, 3) . '0';
		}
		
		if (isset($meta_xml->contributor)) {
			$contributor = $meta_xml->contributor;
			$contributor_url = preg_replace("/[^A-Za-z0-9]+/", '_', $contributor);
		}
		if (isset($meta_xml->sponsor)) {
			$sponsor = $meta_xml->sponsor;
			$sponsor_url = preg_replace("/[^A-Za-z0-9]+/", '_', $sponsor);
		}
		
		$tags = array();
		$authors = array();
		$subjects = array();
		$publishers = array();
		
		$tags[] = 'bookid:'.$identifier;
		$tags[] = 'bookyear:'.$year;
		$tags[] = 'bookdecade:'.$decade;
		$tags[] = 'bookcentury:'.$century;
		
		foreach ($meta_xml->creator as $c) {
			$tag = preg_replace("/[^A-Za-z0-9]+/", '_', $c);
			$c = preg_replace("/\.([^ ])/", '. $1', $c);
			$authors[] = '<a href="https://www.flickr.com/search/?tags=bookauthor'.$tag.'">'.$c.'</a>';
			$tags[] = 'bookauthor:'.$tag;
		}
	
		foreach ($meta_xml->subject as $c) {
			$tag = preg_replace("/[^A-Za-z0-9]+/", '_', $c);
			$c = preg_replace("/\.([^ ])/", '. $1', $c);
			$subjects[] = '<a href="https://www.flickr.com/search/?tags=booksubject'.$tag.'">'.$c.'</a>';
			$tags[] = 'booksubject:'.$tag;
		}
	
		foreach ($meta_xml->publisher as $c) {
			$tag = preg_replace("/[^A-Za-z0-9]+/", '_', $c);
			$c = preg_replace("/\.([^ ])/", '. $1', $c);
			$publishers[] = '<a href="https://www.flickr.com/search/?tags=bookpublisher'.$tag.'">'.$c.'</a>';
			$tags[] = 'bookpublisher:'.$tag;
		}
		if ($contributor_url) { $tags[] = 'bookcontributor:'.$contributor_url; }
		if ($sponsor_url) { $tags[] = 'booksponsor:'.$sponsor_url; }
		$tags[] = 'bookleafnumber:'.$i['leaf_num'];

		$is_bhl = false;		
		foreach ($meta_xml->collection as $c) {
			$tag = preg_replace("/[^A-Za-z0-9]+/", '_', $c);
			$tags[] = 'bookcollection:'.$tag;
			if ($tag == 'biodiversity') {
				$is_bhl = true;
			}
		}
		$tags[] = '"BHL Collection"';		
		if ($is_bhl) {
			$tags[] = '"BHL Consortium"';
		}
	
		// Build the Description
		$desc = '<strong>Title</strong>: '.$meta_xml->title."\n".
						'<strong>Identifier</strong>: '.$identifier."\n".
						'<strong>Year</strong>: <a href="https://www.flickr.com/search/?tags=bookyear'.$year.'">'.$year.'</a> (<a href="https://www.flickr.com/search/?tags=bookdecade'.$decade.'">'.$decade.'s</a>)'."\n".
						'<strong>Authors</strong>: '.implode('; ', $authors)."\n".
						'<strong>Subjects</strong>: '.implode('; ', $subjects)."\n".
						'<strong>Publisher</strong>: '.implode('; ', $publishers)."\n".
						($contributor_url ? '<strong>Contributing Library</strong>: <a href="https://www.flickr.com/search/?tags=bookcontributor'.$contributor_url.'">'.$contributor.'</a>'."\n" : '').
						($sponsor_url ? '<strong>Digitizing Sponsor</strong>: <a href="https://www.flickr.com/search/?tags=booksponsor'.$sponsor_url.'">'.$sponsor.'</a>'."\n" : '')."\n\n".
	
						'<strong>View Book Page</strong>: <a href="'.$i['access_url'].'">Book Viewer</a>'."\n".
						'<strong>About This Book</strong>: <a href="https://archive.org/details/'.$identifier.'">Catalog Entry</a>'."\n".
						'<strong>View All Images</strong>: <a href="https://www.flickr.com/search/?tags=bookid.'.$identifier.'">All Images From Book</a>'."\n\n".
						
						'Click here to <a href="'.$i['access_url'].'"><strong>view book online</strong></a> to see this illustration in context in a browseable online version of this book.'."\n\n\n".
						
						'<strong>Text Appearing Before Image: </strong>'."\n".'<em>'.$i['pre_text'].'</em>'."\n\n".
						
						'<strong>Text Appearing After Image: </strong>'."\n".'<em>'.$i['post_text'].'</em>'."\n\n\n".
						
						'<strong>Note About Images</strong>'."\n".
						'<em>Please note that these images are extracted from scanned page images that may have been digitally enhanced for readability - coloration and appearance of these illustrations may not perfectly resemble the original work.</em>';
		
		
		# Now we upload to flickr
		print "    Uploading (".($count++)."/".$max."): ".substr($title,0,60)." . . .";
		$filename = $cachedir."/".$identifier."/upload_imgs/".$i['img_filename'];
		$id = $flickr->sync_upload($filename, $title, $desc, implode(" ", $tags), 1, 0, 0);
		if ($year < 1825) { $year = 1825; }		
		$year = $year.'-01-01 00:00:00';
		$r = $flickr->photos_setdates($id, null, $year, 8);
		print "Sync upload. Photo ID = ".$id."\n";
		
	}
	// =================================
	// We're finished!
	// =================================
	cleanup($cachedir, $identifier);
}	

function parse_abbyy_xml($fh) {
	
	// Actually do the parsing	
	$parser = xml_parser_create();
		
	xml_set_element_handler($parser, "startElement", "endElement");
	xml_set_character_data_handler($parser, "charData");
	xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 1);

	while (!feof($fh)) {
		$data = fread($fh, 8192);
		if (!xml_parse($parser, $data, feof($fh))) {
			printf("Error on line %d: %s", xml_get_current_line_number($parser), xml_error_string(xml_get_error_code($parser)));
		}
	}
	
	xml_parser_free($parser);
}

// Function to handle the opening tags in the XML
function startElement($parser, $el_name, $attributes) {				
	// Sorry. We have a lot of global variables. Oh well.
	global $block_type;
	global $images;
	global $text;
	global $pagecount;
	global $scandata;
	global $identifier;
	global $image_id;
	global $have_image;
	global $pages;
	
	switch ($el_name) {
		case 'PAGE':
			// If we found a page tag, we initialize things
			$text = '';
			$have_image = false;
			break;

		case 'BLOCK':
			if ($attributes['BLOCKTYPE'] == 'Text') {
				// If we found a text block, we record this fact in a variable. 
				$block_type = 'text';
				
			} elseif ($attributes['BLOCKTYPE'] == 'Picture') {
				// If we found a picture block then we can decide if it's something we'll keep
				$width = $attributes['R'] - $attributes['L'];
				$height = $attributes['B'] - $attributes['T'];

				if ($width > 200 && $height > 200 && !$scandata[$pagecount]['drop'] && !$scandata[$pagecount]['skip'] && $pagecount > 4) {
					// Yes, we are keeping this image, add it to our array, taking data from various places.
					$text = preg_replace("/[\s\r\n\t ]+/"," ", $text);
					if ($have_image) {
						// Before we do anything, if we had an image earlier, then we assign it's post_text, which ends up being
						// the pre_text of the current images. 
						$images[count($images) -1]['post_text'] = trim($text);
					}
					$have_image = true;
					$images[] = array(
						'identifier'   => $identifier,
						'leaf_num'     => $scandata[$pagecount]['leafnum'],
						'pagenum'      => $scandata[$pagecount]['pagenum'],
						'image_id'     => $image_id,
						'width'        => $width,
						'height'       => $height,
						'img_filename' => $identifier.'.img.'.$image_id.'.'.sprintf('%04d', $scandata[$pagecount]['pagenum']).'.jpg',
						'access_url'   => 'https://archive.org/stream/'.$identifier.'/#page/n'.$scandata[$pagecount]['pagenum'].'/mode/1up',
						'pre_text'     => trim($text), 
						'post_text'    => '', // This will get filled in later, when we close the page or find another image
						'l'            => $attributes['L'],
						't'            => $attributes['T']					
					);
					
					$pages[$pagecount] = true; // Keep track of how many unique pages have images.
					$image_id++; // Count how many images we found.
					$text = ''; // Initialize the text because we added it to the image.
				}					
			}
	}
}

// Function to handle closing tags in the XML
function endElement($parser, $el_name) {
	global $block_type;
	global $images;
	global $text;
	global $pagecount;
	global $have_image;

	switch (strtoupper($el_name)) {
		case 'PAGE':
			// If we're closing a page tag, clean up some things.					
			$pagecount++;
			if ($have_image) {
				// If we had an image, then we need to make sure it's post_text is added.
				end($images);
				$text = preg_replace("/[\s\r\n\t ]+/"," ", $text);
				$images[key($images)]['post_text'] = trim($text);
			}
			break;

		case 'BLOCK':
			if ($block_type == 'text') {
				// If we had a text block, let's be sure we append a space to the text
				// just to keep things clean.
				$block_type = '';
				$text .= ' ';		
			}
			break;
		case 'LINE':
			// The only time we care about line block is to make sure there's a space
			// at the end of the text. 
			$text .= ' ';		
			break;
	}

}

// Function to handle character data in the XML
function charData($parser, $chardata) {
	// All we do here is accumulate the text in a variable.
	global $text;
	$text .= $chardata;		
}

function usage() {
	return "Usage: flickr-upload.php IDENTIFIER NUMBER\n\nIDENTIFIER must be an Internet Archive identifier.\nNUMBER must be an integer between 1 and 15\n\n";
}

function cleanup($cachedir, $identifier) {

	print "Cleaning $cachedir/$identifier\n";
	if (file_exists("$cachedir/$identifier")) {
		print "rm -rf $cachedir/$identifier\n";
		system("rm -rf '$cachedir/$identifier'");
	}
	return;
}
