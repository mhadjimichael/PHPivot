<?php

//PHPivot
/*Supported Features:
    -Nested (infinite) rows and columns
    -Sum and Count Functions
    -Generate HTML Table
        -Ignore empty rows [ setIgnoreBlankValues ]
    -Filters (Equal, Not Equal)
        -Filters support UNIX Wildcards (shell patterns), like *, ?, [ae], etc.
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
        @todo:-Comparisons
        @todo:-Color Max
        @todo:-Color Min
        @todo:-Color average
        @todo:-Conditional (Value comparison)+pass function
        

  @todo
  -NO ROWS
  -Function Filters that give access to data! [TABLE 1]
  -Consecutive Match N Filter
  -Make sure % sum up to exactly 100!
  -DISPLAY AS % OF COLUMN *TOTAL* [TABLE 61]]
  -DISPLAY (AS % OF COLUMN) ROW SUBTOTALS [TABLE 61]
  -Check whether column/row names exist in data source (and display relevant error messages)
  -DISPLAY SPECIFIC ROWS/COLUMNS IRRESPECTIVE OF DATA exists/not!

@done (?)
    -NO ROWS AND MULTIPLE COLUMNS (TABLE 21) (?????)
    -MULTIPLE COLUMNS (TABLE 22)

*/

//TODO: Filters! (user functions?)

class PHPivot{
    const PIVOT_VALUE_SUM = 1;
    const PIVOT_VALUE_COUNT = 2;
    const SORT_ASC = 1;
    const SORT_DESC = 2;
    const COMPARE_EQUAL = 1;
    const COMPARE_NOT_EQUAL = 2;

    const DISPLAY_AS_VALUE = 0;
    const DISPLAY_AS_PERC_DEEPEST_LEVEL = 1;
    const DISPLAY_AS_PERC_COL = 2;
    const DISPLAY_AS_VALUE_AND_PERC_DEEPEST_LEVEL = 3;

    const TYPE_ROW = 'TYPE_ROW';
    const TYPE_COL = 'TYPE_COL';
    const TYPE_COMP = 'TYPE_COMP';

    const FILTER_MATCH_ALL = 0;
    const FILTER_MATCH_ANY = 1;
    const FILTER_MATCH_NONE = 2;

    const FILTER_PHPIVOT = 0;
    const FILTER_USER_DEFINED = 1;


    const COLOR_ALL = 0;
    const COLOR_BY_ROW = 1;
    const COLOR_BY_COL = 2;

    public static $SYSTEM_FIELDS = array('_type','_title');

    protected $_decimal_precision = 0; //Round to nearest integer by default (0 decimals)

    protected $_recordset;
    protected $_table = array();
    protected $_raw_table = array();
    protected $_calculated_columns = array();
    protected $_values = array();
    protected $_values_function = array();
    protected $_values_display = array();
    protected $_columns = array();
    protected $_columns_titles = array();
    protected $_columns_sort = self::SORT_ASC;
    protected $_rows = array();
    protected $_rows_titles = array();
    protected $_rows_sort = self::SORT_ASC;
    protected $_ignore_blanks = false;

    protected $_color_by = PHPivot::COLOR_ALL;
    protected $_color_low = null;
    protected $_color_high = null;
    protected $_color_of = array();

    protected $_cache_rows_unique_values = array();
    protected $_cache_columns_unique_values = array();

    protected $_filters = array();

    public static function create($recordset){
        return new self($recordset);
    }


    public function __construct($recordset){
        $this->_recordset = $recordset;
    }

    public function getRows(){
        return $this->_rows;
    }
    public function getRowsTitles(){
        return $this->_rows_titles;
    }
    public function getColumns(){
        return $this->_columns;
    }
    public function getColumnsTitles(){
        return $this->_columns_titles;
    }

    public function setPivotValueField($values, $function = PHPivot::PIVOT_VALUE_SUM, $display = PHPivot::DISPLAY_AS_VALUE, $title = null){
        $this->_values = $values;
        $this->_values_function = $function;
        $this->_values_display = $display;

        if(!is_null($title)){
            $this->_columns_titles = array($title);
        }

        return $this;
    }

    public function setValueFunction($function = PHPivot::PIVOT_VALUE_SUM){
        $this->_values_function = $function;
        return $this;
    }

    public function setDisplayAs($display = PHPivot::DISPLAY_AS_VALUE){
        $this->_values_display = $display;
        return $this;
    }

    public function setPivotColumnFields($columns, $titles = null){
        if(!is_array($columns)){
            $columns = array($columns);
        }
        if(is_null(($titles))){
            $titles = $columns;
        }
        if(!is_array($titles)){
            $titles = array($titles);
        }
        $this->_columns = $columns;
        $this->_columns_titles = $titles;

        return $this;
    }

    public function setPivotRowFields($rows, $titles = null){
        if(!is_array($rows)){
            $rows = array($rows);
        }
        if(is_null(($titles))){
            $titles = $rows;
        }
        if(!is_array($titles)){
            $titles = array($titles);
        }
        $this->_rows = $rows;
        $this->_rows_titles = $titles;
        return $this;
    }

    public function setIgnoreBlankValues(){
        $this->_ignore_blanks = true;

        return $this;
    }

    public function setColorRange($low = '#00af5d', $high = '#ff0017', $colorBy = null){
        if(is_null($colorBy)){
            $colorBy = PHPivot::COLOR_ALL;
        }
        $this->_color_by = $colorBy;
        $this->_color_low = $low;
        $this->_color_high = $high;

        return $this;
    }

    protected function cleanBlanks(&$point = null){
        if(!$this->_ignore_blanks)return null;

        $countNonBlank = 0;

        if(PHPivot::isDataLevel($point)){
            if(!is_array($point)) return (!is_null($point) && !empty($point) ? 1 : 0);
            else if($point['_type'] == PHPivot::TYPE_COMP) {
                $data_values = PHPivot::pivot_array_values($point);
                for($i = 0 ; $i < count($data_values); $i++){
                    if(!is_null($data_values[$i]) && !empty($data_values[$i])){
                        $countNonBlank++;
                    }
                }
                return $countNonBlank;
            }
            else die('CleanBlanks:else case');
        }

        $point_keys = array_keys($point);

        for($i = count($point_keys) - 1 ; $i >=0 ; $i--){
            if(PHPivot::isSystemField( $point_keys[$i] )) continue;

            if($this->cleanBlanks($point[ $point_keys[$i] ]) > 0){
                $countNonBlank++;
            }else if($point['_type'] == PHPivot::TYPE_ROW){
                unset($point[ $point_keys[$i] ]);
            }
        }
        
        return $countNonBlank;
    }

    public function setSortColumns($sortby){
        $this->_columns_sort = $sortby;

        return $this;
    }

    public function setSortRows($sortby){
        $this->_rows_sort = $sortby;

        return $this;
    }

    public function addCalculatedColumns($col_name, $calc_function, $extra_params = null){
        //if(is_array($col_name) || is_array($calc_function)) die('addCalculatedColumn accepts one C.Column per call, parameters: col. name, col. function name.');
        if(!is_array($col_name) && !is_array($calc_function)){
            $col_name = array($col_name);
            $calc_function = array($calc_function);
            $extra_params = array_fill(0,1,$extra_params);
        }else if (count($col_name) != count($calc_function)){
            die('addCalculatedColumns: column name and function count mismatch.');
        }
        for($i = 0; $i < count($col_name); $i++){
            $calc_col = array();
            $calc_col['name'] = $col_name[$i];

            if(!function_exists($calc_function[$i])) die('Calculated Column function ' . $calc_function[$i] . ' is not defined.');

            $calc_col['function'] = $calc_function[$i];
            $calc_col['extra_params'] = $extra_params[$i];
            array_push($this->_calculated_columns, $calc_col);
        }
        return $this;
    }

    public function addFilter($column, $value, $compare = PHPivot::COMPARE_EQUAL, $match = PHPivot::FILTER_MATCH_ALL){
        $filter = array();
        $filter['type'] = PHPivot::FILTER_PHPIVOT;
        $filter['column'] = $column;
        $filter['value'] = $value;
        $filter['compare'] = $compare;
        $filter['match'] = $match;
        array_push($this->_filters, $filter );

        return $this;
    }

    public function addCustomFilter($filterFn, $extra_params = null){
        $filter = array();
        $filter['type'] = PHPivot::FILTER_USER_DEFINED;
        $filter['function'] = $filterFn;
        $filter['extra_params'] = $extra_params;

        array_push($this->_filters, $filter);
        return $this;
    }

    private function filter_compare($source, $pattern){
        if(is_numeric($source) && is_numeric($pattern)){
            return ($source == $pattern ? 0 : -2);
        }
        return (fnmatch($pattern, $source) ? 0 : -2);
    }

    private function isFilterOK($rs_row){
        $filterResult = true;
        for($i = 0; $i < count($this->_filters) && $filterResult; $i++){

            switch($this->_filters[$i]['type']){

                case PHPivot::FILTER_PHPIVOT:

                    if(is_array($this->_filters[$i]['value'])){
                        $matches = 0;
                        for($j = 0; $j < count($this->_filters[$i]['value']); $j++){
                            switch ($this->_filters[$i]['compare']){
                                case PHPivot::COMPARE_EQUAL:
                                    $matches += ($this->filter_compare($rs_row[$this->_filters[$i]['column']],  $this->_filters[$i]['value'][$j]) == 0 ? 1 : 0);
                                break;
                                case PHPivot::COMPARE_NOT_EQUAL:
                                    $matches= ($this->filter_compare($rs_row[$this->_filters[$i]['column']],  $this->_filters[$i]['value'][$j]) == 0 ? 0 : 1);
                                        if(!$filterResult) return $filterResult;
                                break;
                                default:
                                    die('ERROR: PHPivot: Compare function ' . $this->_filters[$i]['compare'] . ' not defined.' );
                                break;
                            }
                        }

                        switch($this->_filters[$i]['match']){
                            case PHPivot::FILTER_MATCH_ALL:
                                $filterResult = $filterResult && ($matches == count($this->_filters[$i]['value']));
                                if(!$filterResult) return $filterResult;
                            break;
                            case PHPivot::FILTER_MATCH_NONE:
                                $filterResult = $filterResult && ($matches == 0);
                                if(!$filterResult) return $filterResult;
                            break;
                            case PHPivot::FILTER_MATCH_ANY:
                                $filterResult = $filterResult && ($matches > 0);
                                if(!$filterResult) return $filterResult;
                            break;
                            default:
                                die('ERROR: PHPivot: FILTER_MATCH function ' . $this->_filters[$i]['match'] . ' not defined.' );
                            break;
                        }
                        
                    }else{
                        if(!isset($rs_row[$this->_filters[$i]['column']]))die('ERROR: PHPivot: Filter: No such column ' . $this->_filters[$i]['column']);
                        switch ($this->_filters[$i]['compare']){
                            case PHPivot::COMPARE_EQUAL:
                                $filterResult = $filterResult && ($this->filter_compare($rs_row[$this->_filters[$i]['column']],  $this->_filters[$i]['value']) == 0 ? true : false);
                                if(!$filterResult) return $filterResult;
                            break;
                            case PHPivot::COMPARE_NOT_EQUAL:
                                $filterResult = $filterResult && ($this->filter_compare($rs_row[$this->_filters[$i]['column']],  $this->_filters[$i]['value']) != 0 ? true : false);
                                if(!$filterResult) return $filterResult;
                            break;
                            default:
                                die('ERROR: PHPivot: Compare function ' . $this->_filters[$i]['compare'] . ' not defined.' );
                            break;
                        }
                    }
                    break;
                case PHPivot::FILTER_USER_DEFINED:
                    //$new_col_vals = call_user_func( $col_fn, $this->_recordset, $i );
                    //@todo?
                    die('User defined filters not yet implemented!');
                    $filterResult = $filterResult && call_user_func($this->_recordset, $rs_i, $this->_filters[$i]['extra_params']);
                break;
                default:
                    die('Undefined Filter Type: ' . $this->_filters[$i]['_type']);
                break;
            }
        }
        return $filterResult;
    }

    protected function calculateColumns(){
        $recordset_rows = count($this->_recordset);

        foreach($this->_calculated_columns as $calc_col){
            $col_name = $calc_col['name'];
            $col_fn = $calc_col['function'];
            $extra_params = $calc_col['extra_params'];

            for($i = 0; $i < $recordset_rows; $i++){
                if(!empty($extra_params)){
                    $new_col_vals = call_user_func( $col_fn, $this->_recordset, $i, $extra_params );
                }else{
                    $new_col_vals = call_user_func( $col_fn, $this->_recordset, $i );
                }
                if(!is_array($new_col_vals)){
                    $this->_recordset[$i][$col_name] = $new_col_vals;
                }else{
                    foreach($new_col_vals as $key => $val){
                      $this->_recordset[$i][$col_name . '_' . $key]  = $val;
                    }
                }
            }
        }
        return $this;
    }

    public function generate(){
        $table = array();

        if(empty($this->_recordset)){
            return $table;
        }
        //Calculate all CALCULATED COLUMNS
        $this->calculateColumns();

        //Find all rows' and columns' unique "labels"

        //Initialize with an empty list for each row and column
        $rows_unique_values = &$this->_cache_rows_unique_values;
        for($i = 0; $i < count($this->_rows); $i++){
            $rows_unique_values[$this->_rows[$i]] = array();
        }
        $columns_unique_values = &$this->_cache_columns_unique_values;
        for($i = 0; $i < count($this->_columns); $i++){
            $columns_unique_values[$this->_columns[$i]] = array();
        }

        //Iterate through the dataset and add the unique values of interest to the respective arrays
        foreach($this->_recordset as $rs_ind => $rs_row){
            if(!$this->isFilterOK($rs_row)) continue; //Excluded due to filter
            foreach($this->_columns as $col){
                if(!in_array( $rs_row[$col], $columns_unique_values[$col])){
                    array_push($columns_unique_values[$col], $rs_row[$col]);
                }
            }
            foreach($this->_rows as $row_title){
                if(!in_array( $rs_row[$row_title], $rows_unique_values[$row_title])){
                    array_push($rows_unique_values[$row_title], $rs_row[$row_title]);
                }
            }
        }

        //Sort columns and rows names
        foreach($this->_columns as $index => $col){
            $sort = $this->_columns_sort;
            if(is_array($this->_columns_sort)){
                if(isset($this->_columns_sort[$index])){
                    $sort = $this->_columns_sort[$index];
                }else{
                    $sort = PHPivot::SORT_ASC;
                }
            }
            if($sort == PHPivot::SORT_ASC || $sort == PHPivot::SORT_DESC){ //Natural compare algorithm
                natsort($columns_unique_values[$col]);
                if($sort == PHPivot::SORT_DESC){
                    array_reverse($columns_unique_values[$col]);
                }
            }else{ //User defined sort algorithm
                usort($columns_unique_values[$col], $sort);
            }
        }
        foreach($this->_rows as $index => $row){
            $sort = $this->_rows_sort;
            if(is_array($this->_rows_sort)){
                if(isset($this->_rows_sort[$index])){
                    $sort = $this->_rows_sort[$index];
                }
                else{
                    $sort = PHPivot::SORT_ASC;
                }
            }
            if($sort == PHPivot::SORT_ASC || $sort == PHPivot::SORT_DESC){ //Natural compare algorithm
                natsort($rows_unique_values[$row]);
                if($this->_rows_sort == PHPivot::SORT_DESC){
                    array_reverse($rows_unique_values[$row]);
                }
            }else{ //User defined sort algorithm
                usort($rows_unique_values[$row], $sort);
            }
        }

        //Create an associative array with all the unique values for all the columns
        $columns_assoc = null;
        for($i = count($this->_columns) - 1; $i >= 0; $i--){
            $new_columns_assoc = array();
            $new_columns_assoc['_type'] = PHPivot::TYPE_COL;
            $new_columns_assoc['_title'] = $this->_columns_titles[$i];
            $cur_col_values = $columns_unique_values[$this->_columns[$i] ];
            foreach($cur_col_values as $key => $value){
                $new_columns_assoc[$value] = $columns_assoc;
            }
            $columns_assoc = $new_columns_assoc;
        }

        //Create an associative array with all the unique values for all the rows
        $rows_assoc = $columns_assoc; //Each row starts with all the columns
        for($i = count($this->_rows) - 1; $i >= 0; $i--){
            $new_rows_assoc = array();
            $new_rows_assoc['_type'] = PHPivot::TYPE_ROW;
            $new_columns_assoc['_title'] = $this->_rows_titles[$i];
            $cur_row_values = $rows_unique_values[$this->_rows[$i] ];
            foreach($cur_row_values as $key => $value){
                $new_rows_assoc[$value] = $rows_assoc;
            }
            $rows_assoc = $new_rows_assoc;
        }
        $table = $rows_assoc;

        //Iterate throughout the recordset and fill the table
        foreach($this->_recordset as $rs_ind => $rs_row){
            if(!$this->isFilterOK($rs_row)) continue; //Excluded due to filter
            //Traverse and find the right row and column
            $point = &$table;
            for($i = 0; $i < count($this->_rows); $i++){
                $point = &$point[ $rs_row[$this->_rows[$i] ] ];
            }
            for($i = 0; $i < count($this->_columns); $i++){
                $point = &$point[ $rs_row[$this->_columns[$i]] ];
            }
            //Record current data (depends on our PIVOT_VALUE function)
            $value_point = &$point;
            $value_function = $this->_values_function;

            switch($value_function){
                case PHPivot::PIVOT_VALUE_COUNT:
                    if(is_null($value_point)){
                        $value_point = 1;
                    }else{
                        $value_point++;
                    }
                break;

                case PHPivot::PIVOT_VALUE_SUM:
                    if(is_null($value_point)){
                        $value_point = $rs_row[$this->_values];
                    }else{
                        $value_point += $rs_row[$this->_values];
                    }
                break;

                default:
                    die('ERROR: Value function not defined in PHPivot: ' . $value_function);
                break;
            }
        }

        $this->cleanBlanks($table);

        $this->_raw_table = array_merge(array(), $table); //Clone array to "raw table" (used for comparisons)
        $this->formatData($table);
        $this->colorData($table);

        //@debug
        echo "<!-- \n";
        print_r($table);
        echo "-->";
        $this->_table = $table;
        return $this;
    }

    protected static function isSystemField($fieldName){
        for($i = 0; $i < count(PHPivot::$SYSTEM_FIELDS); $i++){
            //if($fieldName == PHPivot::$SYSTEM_FIELDS[$i]){ BUGGY!
            if(strcmp($fieldName,PHPivot::$SYSTEM_FIELDS[$i]) == 0){
                return true;
            }
        }
        return false;
    }

    protected static function isDeepestLevel(&$row){
        foreach($row as $key => $child){
            if(PHPivot::isSystemField($key)) continue;
            if(isset($row[$key]['_type'])){
                return false;
            }
        }
        return true;
    }


    protected static function isDataLevel(&$row){
        return !is_array($row) || (isset($row['_type']) && $row['_type'] == PHPivot::TYPE_COMP);
    }


    private function getValueFromFormat($a){
        if(is_null($a)) return $a;
        switch($this->_values_display){
            case PHPivot::DISPLAY_AS_PERC_DEEPEST_LEVEL:
                $a = round(substr($a, 0, strpos($a, '%')),$this->_decimal_precision);
            break;

            case PHPivot::DISPLAY_AS_VALUE_AND_PERC_DEEPEST_LEVEL:
                $a = round(substr($a, strpos($a,'(')+1, strpos($a,')') - 1),$this->_decimal_precision);
            break;

            case PHPivot::DISPLAY_AS_VALUE:
            break;

            default:
                die('getValueFromFormat not programmed to compare display type: ' . $this->_values_display);
            break;
        }
        return $a;
    }

    private function getCompareBetter($a,$b,$findMax,$pureNums = false){
        if(is_null($a)) return $b;
        if(is_null($b)) return $a;

        if(!$pureNums){
            $a = $this->getValueFromFormat($a);
            $b = $this->getValueFromFormat($b);
        }
       
        if($findMax){
            return ($a > $b ? $a : $b);
        }else{
            return ($a < $b ? $a : $b);
        }
    }

    private function findMax(&$row, $findMax = true){
        if(PHPivot::isDataLevel($row)){
            $v = PHPivot::pivot_array_values($row);
            $find = null;
            if(empty($v)) return null;

            if(is_array($v)){
                $find = $this->getValueFromFormat($v[0]);
                for($i = 1; $i < count($v); $i++){
                    $find = $this->getCompareBetter($find, $this->getValueFromFormat($v[$i]), $findMax, true);
                }
            }else{
                $find = $this->getValueFromFormat($v);
            }
            return $find;
        }else{
            $find = null;
            $k = PHPivot::pivot_array_keys($row);
            for($i = 0; $i < count($k); $i++){
                $find = $this->getCompareBetter($find, PHPivot::findMax($row[$k[$i]], $findMax), $findMax, true );
            }
            return $find;
        }
    }

    private function findMin(&$row){
        return $this->findMax($row, false);
    }

    private static function hexToRGB($hex){
        $hex = str_replace('#','',$hex);
        $rgb = array();
        $rgb['r'] = hexdec(substr($hex,0,2));
        $rgb['g'] = hexdec(substr($hex,2,2));
        $rgb['b'] = hexdec(substr($hex,4,2));
        return $rgb;
    }

    private static function toHexColor($RGB){
        return sprintf('%02x', ($RGB['r'])) . sprintf('%02x', ($RGB['g'])) . sprintf('%02x', ($RGB['b']));
    }

    private function getColorOf($value){
        switch ($this->_color_by){
            case PHPivot::COLOR_ALL:
                $v = $this->getValueFromFormat($value);
                if(isset($this->_color_of[$v]))
                    return $this->_color_of[$v];
                else
                    return 'inherit';
            break;
            default:
                die('getColorOf not programmed to handle COLOR_BY=' . $this->_color_by);
            break;
        }
    }

    private function colorData(&$row, $row_name = null){
        if(!isset($this->_color_low)) return;
        switch ($this->_color_by){
            case PHPivot::COLOR_ALL:
                //1. Find Min and Max Values
                $min = $this->findMin($row);
                $max = $this->findMax($row);
                // /*@debug */ echo "min=$min and max=$max<br />";
                if($min == $max) return; //Don't color if they're the same!
                $stops = $max-$min+1;
                //2. Calculate colors from min to max value (gradient)
                //@todo: Bezier increments (smoother gradients)
                //http://bsou.io/posts/color-gradients-with-python
                $fromColor = PHPivot::hexToRGB($this->_color_low);
                $toColor = PHPivot::hexToRGB($this->_color_high);
                $stepBy = array(
                        'r' => (($fromColor['r'] - $toColor['r'])/($stops-1)),
                        'g' => (($fromColor['g'] - $toColor['g'])/($stops-1)),
                        'b' => (($fromColor['b'] - $toColor['b'])/($stops-1))
                    );
                $curColor = array_merge(array(), $fromColor);

                for($i=$min;$i<=$max;$i++){
                    //$this->_color_of[$i] = '#' . PHPivot::toHexColor($curColor);
                    $this->_color_of[$i] = 'rgba(' . $curColor['r'] . ',' .$curColor['g'] . ','.$curColor['b'] . ',0.8)'; 
                    $curColor['r'] = floor($fromColor['r'] - $stepBy['r'] * $i);
                    $curColor['g'] = floor($fromColor['g'] - $stepBy['g'] * $i);
                    $curColor['b'] = floor($fromColor['b'] - $stepBy['b'] * $i);
                }
/*
                if(!PHPivot::isDataLevel($row)){
                    $arr_keys = PHPivot::pivot_array_keys($row);
                     for($i = 0; $i < count($arr_keys); $i++){
                        $this->colorData($row[$arr_keys[$i]]);
                    }
                }else{

                }*/
            break;
            case PHPivot::COLOR_BY_ROW:
                //@todo
                die('PHPivot:: COLOR_BY_ROW not yet implemented.');
            break;
            case PHPivot::COLOR_BY_COL:
                //@todo
                die('PHPivot:: COLOR_BY_COL not yet implemented.');
            break;
            default:
                die('PHPivot ERROR: Cannot color data by ' . $this->_color_by);
            break;
        }
    }


    private function formatData(&$row){
        switch ($this->_values_display){
            case PHPivot::DISPLAY_AS_VALUE:
                return;
            break;

            case PHPivot::DISPLAY_AS_PERC_DEEPEST_LEVEL:
                if(!is_array($row))return; //Empty table
                //If we didn't reach a "deepest level" array, it means we didn't reach the values yet
                //so go deeper.
                if(!empty($row) && !PHPivot::isDeepestLevel($row)){
                    for($i = 0; $i < count(array_keys($row)); $i++){
                        if($this->isSystemField(array_keys($row)[$i])) continue;

                        $this->formatData($row[array_keys($row)[$i]]);
                    }
                    return ;
                }
                //If we reach here it means we have values, so format them appropriately
                //Calculate Row sum
                $sum = 0;
                foreach($row as $key => $value){
                    if($this->isSystemField($key)) continue;

                    $sum += $value;
                }
                //If sum > 0 (avoid division with 0)
                //Calculate all the %
                if($sum > 0){
                    $keys = array_keys($row);
                    for($i = 0; $i < count($keys); $i++){
                        if($this->isSystemField($keys[$i])) continue;
                        $actual_value = $row[$keys[$i]];
                        if(isset($actual_value)){
                            $row[$keys[$i]] = round($row[$keys[$i]]*100/$sum, $this->_decimal_precision) . '%';
                        }
                    }
                }
            break;
            case PHPivot::DISPLAY_AS_VALUE_AND_PERC_DEEPEST_LEVEL:
                if(!is_array($row))return; //Empty table
                //If we didn't reach a "deepest level" array, it means we didn't reach the values yet
                //so go deeper.
                if(!empty($row) && !PHPivot::isDeepestLevel($row)){
                    for($i = 0; $i < count(array_keys($row)); $i++){
                        if($this->isSystemField(array_keys($row)[$i])) continue;

                        $this->formatData($row[array_keys($row)[$i]]);
                    }
                    return ;
                }
                //If we reach here it means we have values, so format them appropriately
                //Calculate Row sum
                $sum = 0;
                foreach($row as $key => $value){
                    if($this->isSystemField($key)) continue;

                    $sum += $value;
                }
                //If sum > 0 (avoid division with 0)
                //Calculate all the %
                if($sum > 0){
                    $keys = array_keys($row);
                    for($i = 0; $i < count($keys); $i++){
                        if($this->isSystemField($keys[$i])) continue;
                        $actual_value = $row[$keys[$i]];
                        if(isset($actual_value)){
                            $row[$keys[$i]] = round($row[$keys[$i]]*100/$sum, $this->_decimal_precision) . '% (' . $actual_value . ')';
                        }
                    }
                }
            break;
            default:
                die('PHPivot ERROR: Cannot format data as format: ' . $display);
            break;
        }
    }

    public function toArray(){
        return $this->_table;
    }

    public function toRawArray(){
        return $this->_raw_table;
    }

    protected static function pivot_array_keys(&$array){
        $keys = array();
        if(!is_array($array)) return $keys;
        foreach($array as $key => $val){
            if(PHPivot::isSystemField($key)) continue;
            array_push($keys, $key);
        }
        return $keys;
    }

    protected static function pivot_array_values(&$array){
        $values = array();
        if(!is_array($array)) return $array;
        foreach($array as $key => $val){
            if(PHPivot::isSystemField($key)) continue;
            array_push($values, $val);
        }
        return $values;
    }

    protected static function countChildrenCols($array){
        $children = 0;
        if(is_array($array) && isset($array['_type']) && $array['_type'] == PHPivot::TYPE_COL){
            foreach($array as $col_name => $col_value){
                if(PHPivot::isSystemField($col_name)) continue;
                $children += PHPivot::countChildrenCols($col_value);
            }
        }
        if($children == 0){ //count self for colspan, if no children
            $children = 1;
        }
        return $children;
    }

    protected function getColHtml(&$colpoint, $row_space, $coldepth = 0, $isLeftmost = true ){
        $html = '';
        if(is_array($colpoint) && count($this->_columns) - $coldepth > 0){
            //$html .= '<tr>' . $row_space;
            //$colwidth *= $colwidth * count(PHPivot::pivot_array_values($colpoint));
            $new_html = '';
            $willBeLeftmost = true;
            foreach($colpoint as $col_name => $col_value){
                if(PHPivot::isSystemField($col_name)) continue;
                $new_html .= $this->getColHtml($col_value, $row_space, $coldepth + 1, $willBeLeftmost);
                $willBeLeftmost = false;
                $html .= '<th colspan="' . $this->countChildrenCols($col_value) . '">' . $col_name . '</th>';
            }
            //$html .= '</tr>';
            if($coldepth == 0){
                return '<tr>' . $row_space . $html . '</tr>' . $new_html;
            }else{
                return ($isLeftmost ? $row_space : '' ) . $html . $new_html;
            }
        }else{
            return '';
        }
    }

    public function toHtml(){
        $row_space = '';
        for($i = 0; $i < count($this->_rows); $i++){
            $row_space .= '<th></th>';
        }

        $html_cols = '';
        //Print Column Values (final level)
        $colpoint = isset(PHPivot::pivot_array_values($this->_table)[0]) ? PHPivot::pivot_array_values($this->_table)[0] : null;
        $rowDepth = 1;
        while(!is_null($colpoint) && count($this->_rows)-$rowDepth > 0){
            $colpoint = isset(PHPivot::pivot_array_values($colpoint)[0]) ? PHPivot::pivot_array_values($colpoint)[0] : null;
            $rowDepth++;
        }
       $html_cols = $this->getColHtml($colpoint,$row_space);
       $colwidth = $this->countChildrenCols($colpoint); //@todo (pointer is missing now!)

        //$html = $html_cols;

        //@todo
        $top_col_title = (isset($this->_columns_titles[0]) ? $this->_columns_titles[0] : '(No title)');

        $html_row_titles = '<tr>';
        for($i = 0; $i < count($this->_rows_titles); $i++){
            $html_row_titles .= '<th class="row_title">' . $this->_rows_titles[$i] . '</th>';
        }
        $html_row_titles .= '</tr>';


        $html = '<table><thead><tr>' . $row_space . '<th colspan="' . $colwidth . '">' 
                    . $top_col_title . '</th></tr>' . $html_cols . $html_row_titles . '</thead>';


        foreach($this->_table as $row_key => $row_data){
            $html .= $this->htmlValues($row_key, $row_data, 0);
        }

        $html .= '</table>';
        return $html;
    }

    protected function htmlValues(&$key, &$row, $levels, $type = null){
        $levelshtml = '';

        for($i = 0; $i < $levels; $i++){
            $levelshtml .= '<td></td>';
        }

        if(!PHPivot::isDataLevel($row)){
            $html = '';
            if($type == null || $type == PHPivot::TYPE_ROW ){ 
                $html .= '<td>' . $key . '</td>';
            }
            foreach($row as $head => $nest){
                if(PHPivot::isSystemField($head)) continue;
                $new_row = $this->htmlValues($head, $nest, $levels+1, $row['_type']);
                $html .=  $new_row;
            }

            if($type == null || $type== PHPivot::TYPE_ROW ){ 
                $html = '<tr>' . $levelshtml . $html .'</tr>';
            }
            return $html;
        }else{
            if (isset($row['_type']) && $row['_type'] == PHPivot::TYPE_COMP){ //Deepest level row, with comparison data
                $comparison_data = '';
                $data_values = PHPivot::pivot_array_values($row);
                for($i = 0 ; $i < count($data_values); $i++){
                    $comparison_data .= $data_values[$i];
                    if($i + 1 < count($data_values)){
                        $comparison_data .= ' => ';
                    }
                }
                if($levels == 0){
                    return '<tr><td>' . $key . '</td><td>' . $comparison_data . '</td></tr>';
                }else{
                    $inNest = ($levels - count($this->_columns) - count($this->_rows) + 1 > 0);
                    if(!$inNest){
                        return '<td>' . $comparison_data . '</td>';
                    }else{
                        return  '<td>' . $key . '</td>' . '<td>' . $comparison_data . '</td>';
                    }
                }
            }
            else if($type == PHPivot::TYPE_ROW ){ //Deepest level row
                $html = '<tr>' . $levelshtml . '<td>' . $key . '</td>';
                $html .= '<td style="background:' . $this->getColorOf($row) . ' !important">' . $row . '</td>';
                return $html . '</tr>';
            }else{ //Deepest level column
                if($levels == 0){
                    if(PHPivot::isSystemField($key)) return '';
                    return '<tr><td>' . $key . '</td><td style="background:' . $this->getColorOf($row) . ' !important">' . $row . '</td></tr>';
                }else{
                    $inNest = ($levels - count($this->_columns) - count($this->_rows) + 1 > 0);
                    if(!$inNest){
                        return '<td style="background:' . $this->getColorOf($row) . ' !important">' . $row . '</td>';
                    }else{
                        return  '<td>' . $key . '</td>' . '<td style="background:' . $this->getColorOf($row) . ' !important">' . $row . '</td>';
                    }
                }
            }
        }
    }

}

?>
