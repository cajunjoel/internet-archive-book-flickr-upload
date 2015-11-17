# internet-archive-book-flickr-upload

This script is used to upload the images from a text object (book) at the Internet Archive to Flickr. 
It takes an Internet Archive Identiifer as the single argument and uses the data at IA to identify,
crop, and upload images to Flickr. The Flickr API keys must be set in the flickrKeys.php file beforehand.
The script CAN use multiple Flickr API keys, but this is STRONGLY discouraged.

AUTHOR:        Joel Richard, Smithsonian Libraries (richardjm@si.edu)
 
DATE CREATED:  January 14, 2014 
 
NOTES:         * Based on the fullresolutionimageextractor.pl script written by Kalev Leetaru.
               * Comments labeled TODO should be addressed before putting into production. :)
               * Uses an event-based XML parser to conserve memory. ABBYY XML files are ginormous.

REQUIRES:      PHP 5.5+
               The PHPFlickr Library: https://github.com/dan-coulter/phpflickr
               ImageMagick's "convert" command: http://www.imagemagick.org/
               OR Kakadu Software's "kdu_buffered_expand" command: http://www.kakadusoftware.com/
               The Linux "wget" command
               The Linux "zip" command

USAGE:         php flickr_upload.php IDENTIFIER_TO_PROCESS

CHANGES:       2014-01-14: JMR - Created

