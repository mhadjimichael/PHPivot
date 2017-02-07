<!--
    You can include any HTML/CSS you need
    here including sample CSS
-->
<link rel="stylesheet" href="style.css" />
<h2>Jump to:</h2>
<ul>
    <li><a href="#genre">Films by Genre</a></li>
    <li><a href="#actorGenre">Films by Actor and Genre</a></li>
    <li><a href="#actorYear">Films by Genre and Year</a></li>
</ul>
<?php
	require '../PHPivot.php';

    //Get the data
    //Could be from a database, JSON, etc

    //Just needs to be saved in an associative PHP array
    //First row: headers, subsequent rows: data

    //here: read JSON and make associative array
    $data = json_decode(file_get_contents('FilmDataSet.json'), true);


    echo '<a name="genre"></a><h1>Films by Genre</h1>';
    $filmsByGenre = PHPivot::create($data)
            ->setPivotRowFields('Genre')
            ->setPivotValueFields('Genre',PHPivot::PIVOT_VALUE_COUNT, PHPivot::DISPLAY_AS_VALUE_AND_PERC_COL, 'Frequency of Genre')
            ->addFilter('Genre','', PHPivot::COMPARE_NOT_EQUAL) //Filter out blanks/unknown genre
            ->generate();
    echo $filmsByGenre->toHtml();

    echo '<a name="actorGenre"></a><h1>Films by Actor and Genre</h1>';
    $filmsByActorAndGenre = PHPivot::create($data)
            ->setPivotRowFields('Actor')
            ->setPivotColumnFields('Genre')
            ->setPivotValueFields('Genre',PHPivot::PIVOT_VALUE_COUNT, PHPivot::DISPLAY_AS_VALUE_AND_PERC_ROW, 'Frequency of Genre by Actor')
            ->addFilter('Genre','', PHPivot::COMPARE_NOT_EQUAL) //Filter out blanks/unknown genre
            ->generate();
    echo $filmsByActorAndGenre->toHtml();

    echo '<a name="actorYear"></a><h1>Films by Genre and Year</h1>';
    $filmsByGenreAndYear = PHPivot::create($data)
            ->setPivotRowFields(array('Year','Genre'))
            ->setPivotValueFields('Genre',PHPivot::PIVOT_VALUE_COUNT, PHPivot::DISPLAY_AS_VALUE, 'Frequency of Genre in each year')
            ->addFilter('Genre','', PHPivot::COMPARE_NOT_EQUAL) //Filter out blanks/unknown genre
            ->setIgnoreBlankValues()
            ->generate();

    echo $filmsByGenreAndYear->toHtml();
?>
