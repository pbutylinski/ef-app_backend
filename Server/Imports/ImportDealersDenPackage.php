<?php

define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require SHARED_PATH . 'helpers.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';
require VENDOR_PATH . 'parsecsv/php-parsecsv/parsecsv.lib.php';

use \YaLinqo\Enumerable;

set_error_handler("exceptionErrorHandler", E_ALL);

$database = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$database->throw_exception_on_error = true;
$database->error_handler = false;

$zipArchiveLocation = "/temp/EF22.zip";

$zipContentsQueryable = getZipContentsAsQueryable($zipArchiveLocation);
$csvEntry = $zipContentsQueryable->where(function($v) { return endsWith($v["name"], "csv"); })->single();

$csvData = getZipContentOfFile($zipArchiveLocation, $csvEntry['name']);
while(ord($csvData[0]) > 127) $csvData = substr($csvData, 1);

$csvParser = new parseCSV();
$csvParser->delimiter = ";";
$csvContentsQueryable =  from($csvParser->parse_string(utf8_decode($csvData)));

try {
    
    $database->startTransaction();

    $existingRecordsQueryable = from($database->query("SELECT * FROM dealer"));

    $oldRecords = $existingRecordsQueryable
        ->where('$v["IsDeleted"] == 0')
        ->where(function($row) use ($csvContentsQueryable) { return $csvContentsQueryable->all('$v["Reg No."] != '.$row['RegistrationNumber']); })
        ->toArray();
        
    echo sprintf("Soft deleting old records on '%s'\n", 'dealer');

    from($oldRecords)->each(function($row) use($database) {
        echo sprintf("Deleting %s\n", $row['RegistrationNumber']);
        $database->update("dealer", array(
                "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                "IsDeleted" => 1
        ), "Id=%s", $row["Id"]);
    });

    echo sprintf("Inserting / updating records on '%s'\n", 'dealer');

    from($csvContentsQueryable)->each(function($record) use($database, $existingRecordsQueryable, $zipArchiveLocation) {
        $row = array(
            "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
            "IsDeleted" => "0",
            "RegistrationNumber" => $record["Reg No."],
            "AttendeeNickname" => $record["Nick"],
            "DisplayName" => $record["Display Name"],
            "ShortDescription" => $record["Short Description"],
            "AboutTheArtistText" => $record["About the Artist"],
            "AboutTheArtText" => $record["About the Art"],
            "WebsiteUri" => $record["Website"],
            "ArtPreviewCaption" => $record["Art Preview Caption"]
        );
        
        $zipContentsQueryable = getZipContentsAsQueryable($zipArchiveLocation);
        
        // Artist Thumbnail
        $artistThumbnailImageEntry = from($zipContentsQueryable)
            ->where(function($v) use($row) { return (strpos($v["name"], sprintf("thumbnail_%s.", $row['RegistrationNumber'])) !== false); })
            ->singleOrDefault();

        $artistThumbnailImageKey = sprintf("dealer:artistThumbnailImage[%s]", $row['RegistrationNumber']);
        
        if (!$artistThumbnailImageEntry) {
            $row["ArtistThumbnailImageId"] = null;
            deleteImageByTitle($database, $artistThumbnailImageKey); 
        } else {
            $imageData = getZipContentOfFile($zipArchiveLocation, $artistThumbnailImageEntry['name']);
            $row["ArtistThumbnailImageId"] = insertOrUpdateImageByTitle($database, $imageData, $artistThumbnailImageKey);
        }
        
        // Artist Image
        $artistImageEntry = from($zipContentsQueryable)
            ->where(function($v) use($row) { return (strpos($v["name"], sprintf("artist_%s.", $row['RegistrationNumber'])) !== false); })
            ->singleOrDefault();

        $artistImageKey = sprintf("dealer:artistImage[%s]", $row['RegistrationNumber']);
        
        if (!$artistImageEntry) {
            $row["ArtistImageId"] = null;
            deleteImageByTitle($database, $artistImageKey); 
        } else {
            $imageData = getZipContentOfFile($zipArchiveLocation, $artistImageEntry['name']);
            $row["ArtistImageId"] = insertOrUpdateImageByTitle($database, $imageData, $artistImageKey);
        }
        
        // Art Preview Image
        $artPreviewImageEntry = from($zipContentsQueryable)
            ->where(function($v) use($row) { return (strpos($v["name"], sprintf("artist_%s.", $row['RegistrationNumber'])) !== false); })
            ->singleOrDefault();

        $artPrevieyImageKey = sprintf("dealer:artPreviewImage[%s]", $row['RegistrationNumber']);
        
        if (!$artPreviewImageEntry) {
            $row["ArtPreviewImageId"] = null;
            deleteImageByTitle($database, $artPrevieyImageKey); 
        } else {
            $imageData = getZipContentOfFile($zipArchiveLocation, $artPreviewImageEntry['name']);
            $row["ArtPreviewImageId"] = insertOrUpdateImageByTitle($database, $imageData, $artPrevieyImageKey);
        }

        $existingRow = from($existingRecordsQueryable)->where('$v["RegistrationNumber"] == ' . $row['RegistrationNumber'])->singleOrDefault();
            
        if ($existingRow) {
            echo sprintf("Updating %s\n", $row['RegistrationNumber']);
            $database->update("dealer", $row, "Id=%s", $existingRow["Id"]);
        } else {
            $row["Id"] = $database->sqleval("uuid()");
            echo sprintf("Inserting %s\n", $row['RegistrationNumber']);
            $database->insert("dealer", $row);
        }
    });
    
    echo "Commiting changes to database\n";
    $database->commit();
    
} catch (Exception $e) {
    
    var_dump($e->getMessage());
    
    echo "Rolling back changes to database\n";
    $database->rollback();
    
}

?>