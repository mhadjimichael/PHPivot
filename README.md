# PHPivot
A flexible Pivot Table library for PHP.

*Supported Features:*
-Nested (infinite) rows and columns
-Sum and Count Functions
-Generate HTML Table
    -Ignore empty rows [ setIgnoreBlankValues ]
-Filters (Equal, Not Equal)
    -Filters support UNIX Wildcards (shell patterns), like \*, ?, [ae], etc.
        (see php.net/fnmatch )
    -Support for Multiple Values matched as ALL(AND)/OR(ANY)/NONE(NOR) (MATCH_ALL, MATCH_NONE, MATCH_ANY)
    -@todo? Additional User-Defined functions as Filters
        -addCustomFilter( user_defined_filter_function, $extra_params = null )
            -@user_defined_filter_function($recordset, $rowID, $extra_params = null) -> returns true whenever a row should be INCLUDED.
    -User-defined "filters" can be setup using calculated columns and regular filters!
-Calculated Columns
    -User defined functions.
        -They can return an array with "key-value" pairs, resulting in multiple calculated columns,
            named as CALC_COL_NAME_KEY
-Sorting(Ascending by default, Descending, User defined functions)
    -Different Row and Column Sorting methods
    -Can give array argument for multiple level/different sorting
    -User-defined sorting functions
        -@user-defined-sorting-function($a,$b) -> should return $a < $b
-Display as:
    -Actual Values
    -Percentage of deepest level
-Color Coding (background) of data:
    -Low->High/High->Low gradient

*Usage example:*
```php
require 'PHPivot.php';

//@table: a 2D array containing the row headers in the first (0) row followed by the data
function getPivotHTML($table) {
  $mypivot = PHPivot::create( $table )
                  ->setPivotValueField('Earnings', PHPivot::PIVOT_VALUE_SUM, PHPivot::DISPLAY_AS_PERC_DEEPEST_LEVEL,'Earnings %') //Set Pivot Value Field to area, calculate sum and display as percentage.
                  ->setPivotRowFields('Area', 'Area') //Show by Area (rows)
                  ->setSortColumns(PHPivot::SORT_DESC) //Sort Earnings Descending
                  ->setSortRows('SORT_BY_AREA_CUSTOM_FN') //Sort Area by custom function (defined by the user)
                  ->addFilter('Area',array('A*','B*')) //Only take into account areas starting with A or B
                  ->addFilter('Earnings','0', PHPivot::COMPARE_NOT_EQUAL) //ignore no earnings
                  ->generate();

  return $mypivot->toHtml(); //Return HTML formatted table
}
```
