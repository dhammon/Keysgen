<?php
require_once __DIR__ . "/../config/Config.php";


class BittingDecoder
{
    //set by profile
    private $keyLength;
    private $shoulderToFirstCut;
    private $cylinderSpacing;
    private $cylinderCount;
    private $bowToShoulderDistance;
    private $depthSpacing;
    private $bladeHeight;
    private $depthLevels;
    private $depthFirstLevel;

    //set by constructor
    private $image;
    private $columnCount;
    private $rowCount;
    private $imageStats;

    //set in this class
    private $keyColorHighValue;
    private $keyColorLowValue;
    private $rowKeyStarts;
    private $rowKeyEnds;

    //set by config
    private $keyCodeMin;
    private $keyCodeMax;
    private $backgroundColorThreshold;
    private $noiseScale;
    private $minColSampleCount;
    private $sampleAmount;

    public function __construct(array $profile, $image, $imageStats)
    {
        $this->keyLength = $profile['keyLength'];
        $this->shoulderToFirstCut = $profile['shoulderToFirstCut'];
        $this->cylinderSpacing = $profile['cylinderSpacing'];
        $this->cylinderCount = $profile['cylinderCount'];
        $this->bowToShoulderDistance = $profile['bowToShoulderDistance'];
        $this->depthSpacing = $profile['depthSpacing'];
        $this->bladeHeight = $profile['bladeHeight'];
        $this->depthLevels = $profile['depthLevels'];
        $this->depthFirstLevel = $profile['depthFirstLevel'];

        $this->image = $image;
        $this->columnCount = imagesx($this->image);
        $this->rowCount = imagesy($this->image);
        $this->imageStats = $imageStats;
        $this->keyCodeMin = Config::$keyCodeMin;
        $this->keyCodeMax = Config::$keyCodeMax;
        $this->backgroundColorThreshold = Config::$backgroundColorThreshold;
        $this->noiseScale = Config::$noiseScale;
        $this->minColSampleCount = Config::$minColSampleCount;
        $this->sampleAmount = Config::$sampleAmount;
    }

    public function decodeKey()
    {
        $this->analyzeImage();
        $this->rotateKey();
        $this->analyzeImage();
        $this->flipKey();
        //return $this->image; //todo delete
        $keyCodeArray = $this->measureBittings();
        $keyCodeString = $this->convertKeyCodeArrayToString($keyCodeArray);
        $this->checkKeyCodeQuality($keyCodeString);

        return $keyCodeString;
    }

    private function checkKeyCodeQuality($keyCode)
    {
        if($keyCode < $this->keyCodeMin || $keyCode > $this->keyCodeMax)
        {
            throw new ExceptionKeyDecoder("Key code is poor quality");
        }
    }

    private function convertKeyCodeArrayToString($keyCodeArray)
    {
        $keyCodeString = "";

        foreach($keyCodeArray as $value)
        {
            $keyCodeString .= "$value";
        }

        return $keyCodeString;
    }

    private function measureBittings()
    {
        $this->setRowKeyStarts();
        $this->setRowKeyEnds();
        $keyScale = $this->calculateKeyScale();
        $rowsWithCuts = $this->findRowsWithCuts($keyScale);
        $colDistanceOfCuts = $this->measureColDistanceOfCuts($rowsWithCuts);
        $keyCutTable = $this->makeKeyCutTable($keyScale);
        $keyCode = $this->lookupKeyCode($keyCutTable, $colDistanceOfCuts);

        return $keyCode;
    }

    private function lookupKeyCode(array $keyCutTable, $colDistanceOfCuts)
    {
        $keyCode = array();

        foreach($colDistanceOfCuts as $cut)
        {
            foreach($keyCutTable as $row)
            {
                $high = $row['high'];
                $low = $row['low'];

                if($cut >= $low && $cut <= $high)
                {
                    $keyCode[] = $row['keyCode'];
                }
            }
        }

        return $keyCode;
    }

    private function makeKeyCutTable($keyScale)
    {
        $baseTable = $this->makeBaseKeyCutTable();
        $basePlusHighLowTable = $this->addHighLowColumns($baseTable);
        $keyCutTable = $this->convertKeyCutTableToPixels($basePlusHighLowTable, $keyScale);

        return $keyCutTable;
    }

    private function convertKeyCutTableToPixels(array $baseTable, $keyScale)
    {
        foreach($baseTable as $key=>$row)
        {
            $baseTable[$key]['value'] = $row['value'] * $keyScale;
            $baseTable[$key]['high'] = $row['high'] * $keyScale;
            $baseTable[$key]['low'] = $row['low'] * $keyScale;
        }

        return $baseTable;
    }

    private function addHighLowColumns(array $keyCutTable)
    {
        $newKeyCutTable = array();
        $rowCount = count($keyCutTable);
        $valueDifference = $keyCutTable[0]['value'] - $keyCutTable[1]['value'];
        $halfValueDifference = $valueDifference / 2;

        for($i=0; $i<$rowCount; $i++)
        {
            if($i == 0)
            {
                $highValue = 9999999999;
            }
            else
            {
                $highValue = $keyCutTable[$i]['value'] + $halfValueDifference;
            }

            if($i == $rowCount - 1)
            {
                $lowValue = 0;
            }
            else
            {
                $lowValue = $keyCutTable[$i]['value'] - $halfValueDifference;
            }

            $newKeyCutTable[] = array(
                "keyCode"=>$keyCutTable[$i]['keyCode'],
                "value"=>$keyCutTable[$i]['value'],
                "high"=>$highValue,
                "low"=>$lowValue
            );
        }

        return $newKeyCutTable;
    }

    private function makeBaseKeyCutTable()
    {
        $depthSpacing = $this->depthSpacing;
        $bladeHeight = $this->bladeHeight;
        $depthLevels = $this->depthLevels;
        $depthFirstLevel = $this->depthFirstLevel;
        $depthAdjustment = 0;
        $keyCutTable = array();

        if($depthFirstLevel == 0)
        {
            $depthAdjustment = 1;
        }

        for($i=$depthFirstLevel; $i<$depthLevels+$depthFirstLevel+$depthAdjustment; $i++)
        {
            $depthMultiplier = $i + $depthAdjustment;
            $keyCutTable[] = array("keyCode"=>$i, "value"=>$bladeHeight - ($depthMultiplier * $depthSpacing));
        }

        return $keyCutTable;
    }

    private function measureColDistanceOfCuts(array $rowsWithCuts)
    {
        $colDistances = array();

        foreach($rowsWithCuts as $row)
        {
            $colStart = $this->rowKeyStartPosition($row);
            $colEnd = $this->rowKeyEndPosition($row);
            $colDistances[] = (int) round($colEnd - $colStart);
        }

        return $colDistances;
    }

    private function findRowsWithCuts($keyScale)
    {
        $shoulderToFirstCut = $this->shoulderToFirstCut;
        $cylinderSpacing = $this->cylinderSpacing;
        $numberOfCylinders = $this->cylinderCount;
        $bowToShoulderDistance = $this->bowToShoulderDistance;
        $rowKeyStarts = $this->rowKeyStarts;
        $this->validateIsNumericGreaterThanZero($rowKeyStarts, "rowKeyStarts");

        $endOfShoulderRow = $bowToShoulderDistance * $keyScale + $rowKeyStarts;
        $firstCutRow = $shoulderToFirstCut * $keyScale + $endOfShoulderRow;
        $rowsBetweenCuts = $cylinderSpacing * $keyScale;

        $rowsWithCuts[] = (int) round($firstCutRow, 0);
        for($i=1; $i<$numberOfCylinders; $i++)
        {
            $rowsWithCuts[] = (int) round($i * $rowsBetweenCuts + $firstCutRow, 0);
        }

        return $rowsWithCuts;
    }

    private function calculateKeyScale()
    {
        //mm to rows
        $rowKeyStarts = $this->rowKeyStarts;
        $rowKeyEnds = $this->rowKeyEnds;
        $keyLength = $this->keyLength;
        $this->validateIsNumericGreaterThanZero($rowKeyStarts, "rowKeyStarts");
        $this->validateIsNumericGreaterThanZero($rowKeyEnds, "rowKeyEnds");
        $this->validateIsNumericGreaterThanZero($keyLength, "keyLength");

        $imageKeySize = $rowKeyEnds - $rowKeyStarts;
        $keyScale = $imageKeySize / $keyLength;

        return $keyScale;
    }

    private function flipKey()
    {
        $rowPairs = $this->matchPointPairsOnRows();
        $rowDistances = $this->findRowDistances($rowPairs);
        $widestRowKey = $this->findWidestRowKey($rowDistances);
        $flipCheck = $this->checkIfKeyNeedsFlipping($widestRowKey, count($rowDistances));

        if($flipCheck == 1)
        {
            imageflip($this->image, IMG_FLIP_VERTICAL);
        }
    }

    private function checkIfKeyNeedsFlipping($widestRowKey, $rowDistancesCount)
    {
        if($widestRowKey < $rowDistancesCount/2)
        {
            $flipCheck = 0;
        }
        else
        {
            $flipCheck = 1;
        }

        return $flipCheck;
    }

    private function findWidestRowKey($rowDistances)
    {
        $widestRowKey = null;
        $widestDistance = 0;

        foreach($rowDistances as $key=>$value)
        {
            if($value['distance'] > $widestDistance)
            {
                $widestDistance = $value['distance'];
                $widestRowKey = $key;
            }
        }

        return $widestRowKey;
    }

    private function findRowDistances($rowPairs)
    {
        $rowDistances = array();

        foreach($rowPairs as $pair)
        {
            $row = $pair[0][0];
            $leftPair = $pair[0];
            $rightPair = $pair[1];
            $distance = $this->distanceBetweenPoints($leftPair, $rightPair);
            $rowDistances[] = array("distance"=>$distance, "row"=>$row);
        }

        return $rowDistances;
    }

    private function matchPointPairsOnRows()
    {
        $rowPairs = array();
        $pointsRight = $this->findKeyPointsRight();
        $pointsLeft = $this->findKeyPointsLeft();

        foreach($pointsLeft as $leftPoint)
        {
            $leftRow = $leftPoint[0];

            foreach($pointsRight as $rightPoint)
            {
                $rightRow = $rightPoint[0];

                if($leftRow == $rightRow)
                {
                    $rowPairs[] = array($leftPoint, $rightPoint);
                }
            }
        }

        return $rowPairs;
    }

    private function rotateKey()
    {
        $image = $this->image;
        $keyAngle = abs($this->findKeyAngle());
        $rotationNeeded = $this->calculateRotationNeeded($keyAngle);
        $color = $this->imageStats['averages']['top'];

        $this->image = imagerotate($image, $rotationNeeded, $color);
        $this->rowCount = imagesy($this->image);
        $this->columnCount = imagesx($this->image);
    }

    private function calculateRotationNeeded($keyAngle)
    {
        switch($keyAngle)
        {
            case $keyAngle > 0 and $keyAngle <= 90:
                $rotationNeeded = 90 - $keyAngle;
                break;

            case $keyAngle > 90 and $keyAngle <= 270:
                $rotationNeeded = 270 - $keyAngle;
                break;

            case $keyAngle > 270 and $keyAngle <= 360:
                $rotationNeeded = 360 - $keyAngle + 90;
                break;

            default:
                throw new ExceptionKeyDecoder("Key rotation not determined");
        }

        return $rotationNeeded;
    }

    private function findKeyAngle()
    {
        $furthestPointPair = $this->findFurthestPointPair();
        $keyAngle = $this->calculateAngle($furthestPointPair);

        return $keyAngle;
    }

    private function calculateAngle(array $pointPair)
    {
        $x1 = $pointPair[0][1];
        $y1 = $pointPair[0][0];
        $x2 = $pointPair[1][1];
        $y2 = $pointPair[1][0];

        $keyAngle = rad2deg(atan2(($y2-$y1), ($x2-$x1)));

        if($keyAngle < 0)
        {
            $keyAngle += 360;
        }

        return $keyAngle;
    }

    private function findFurthestPointPair()
    {
        $keyPointsLeft = $this->findKeyPointsLeft();
        $keyPointsRight = $this->findKeyPointsRight();
        $combinedPoints = array_merge($keyPointsLeft, $keyPointsRight);
        $furthestPointsArray = $this->makeFurthestPointsArray($combinedPoints);
        $distancesBetweenEachPoint = $this->findDistancesBetweenEachPoint($furthestPointsArray);
        $furthestPointPair = $this->selectTwoFurthestPoints($distancesBetweenEachPoint);

        return $furthestPointPair;
    }

    private function selectTwoFurthestPoints($distancesBetweenEachPoint)
    {
        $furthestPointPair = null;
        $maxDistance = 0;

        foreach($distancesBetweenEachPoint as $pointPairs)
        {
            $distance = $pointPairs[0];

            if($distance > $maxDistance)
            {
                $maxDistance = $distance;
                $furthestPointPair = array($pointPairs[1], $pointPairs[2]);
            }
        }

        return $furthestPointPair;
    }

    private function findDistancesBetweenEachPoint(array $furthestPointsArray)
    {
        $pointA = $furthestPointsArray[0];
        $pointB = $furthestPointsArray[1];
        $pointC = $furthestPointsArray[2];
        $pointD = $furthestPointsArray[3];

        $distances[] = array($this->distanceBetweenPoints($pointA, $pointB), $pointA, $pointB);
        $distances[] = array($this->distanceBetweenPoints($pointA, $pointC), $pointA, $pointC);
        $distances[] = array($this->distanceBetweenPoints($pointA, $pointD), $pointA, $pointD);
        $distances[] = array($this->distanceBetweenPoints($pointB, $pointC), $pointB, $pointC);
        $distances[] = array($this->distanceBetweenPoints($pointB, $pointD), $pointB, $pointD);
        $distances[] = array($this->distanceBetweenPoints($pointC, $pointD), $pointC, $pointD);

        return $distances;
    }

    private function distanceBetweenPoints(array $point1, array $point2)
    {
        $x1 = $point1[1];
        $y1 = $point1[0];
        $x2 = $point2[1];
        $y2 = $point2[0];

        $distance = sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));

        return $distance;
    }

    private function makeFurthestPointsArray(array $combinedPoints)
    {
        $maxRowPoint = $combinedPoints[0];
        $maxColPoint = $combinedPoints[0];
        $minRowPoint = $combinedPoints[0];
        $minColPoint = $combinedPoints[0];
        $maxRow = $combinedPoints[0][0];
        $maxCol = $combinedPoints[0][1];
        $minRow = $combinedPoints[0][0];
        $minCol = $combinedPoints[0][1];

        foreach($combinedPoints as $point)
        {
            $row = $point[0];
            $col = $point[1];

            if($row > $maxRow)
            {
                $maxRowPoint = $point;
                $maxRow = $row;
            }

            if($col > $maxCol)
            {
                $maxColPoint = $point;
                $maxCol = $col;
            }

            if($row < $minRow)
            {
                $minRowPoint = $point;
                $minRow = $row;
            }

            if($col < $minCol)
            {
                $minColPoint = $point;
                $minCol = $col;
            }
        }

        $potentialFurthestPoints[] = $maxRowPoint;
        $potentialFurthestPoints[] = $maxColPoint;
        $potentialFurthestPoints[] = $minRowPoint;
        $potentialFurthestPoints[] = $minColPoint;

        return $potentialFurthestPoints;
    }

    private function findKeyPointsRight()
    {
        $image = $this->image;
        $colCount = imagesx($image);
        $keyPointsRight = null;
        imageflip($image, IMG_FLIP_HORIZONTAL);
        $keyPoints = $this->findKeyPointsLeft();

        foreach($keyPoints as $keyPoint)
        {
            $pointRow = $keyPoint[0];
            $pointCol = $keyPoint[1];
            $keyPointsRight[] = array($pointRow, $colCount - $pointCol);
        }

        imageflip($image, IMG_FLIP_HORIZONTAL);

        return $keyPointsRight;
    }

    private function findKeyPointsLeft()
    {
        $rowKeyEnds = $this->rowKeyEnds;
        $rowKeyStarts = $this->rowKeyStarts;
        $this->validateIsNumericGreaterThanZero($rowKeyEnds, "rowKeyEnds");
        $this->validateIsNumericGreaterThanZero($rowKeyStarts, "rowKeyStarts");
        $rowCount = $rowKeyEnds - $rowKeyStarts;
        $freq = round($rowCount / 24);
        $keyPointsLeft = null;

        $firstRowColumn = $this->rowKeyStartPosition($rowKeyStarts);
        $keyPointsLeft[] = array($rowKeyStarts, $firstRowColumn);

        for($row=$rowKeyStarts+1; $row<=$rowKeyEnds+$freq; $row += $freq)
        {
            $columnFound = $this->rowKeyStartPosition($row);

            if(!is_null($columnFound))
            {
                $keyPointsLeft[] = array($row, $columnFound);
            }
        }

        $lastRowColumn = $this->rowKeyStartPosition($rowKeyEnds-1);
        $keyPointsLeft[] = array($rowKeyEnds, $lastRowColumn);

        return $keyPointsLeft;
    }

    private function analyzeImage()
    {
        $this->setKeyColorRange();
        $this->setRowKeyStarts();
        $this->setRowKeyEnds();
    }

    private function setRowKeyEnds()
    {
        $rowKeyEnds = $this->findLastRowKeyIsIn();

        $this->rowKeyEnds = $rowKeyEnds;
    }

    private function setRowKeyStarts()
    {
        $rowKeyStarts = $this->findFirstRowKeyIsIn();

        $this->rowKeyStarts = $rowKeyStarts;
    }

    private function findLastRowKeyIsIn()
    {
        $image = $this->image;
        $rowCount = imagesy($image);

        imageflip($image, IMG_FLIP_VERTICAL);
        $rowKeyStartsInverted = $this->findFirstRowKeyIsIn();
        $rowKeyEnds = $rowCount - $rowKeyStartsInverted;
        imageflip($image, IMG_FLIP_VERTICAL);

        return $rowKeyEnds;
    }

    private function findFirstRowKeyIsIn()
    {
        $rowCount = imagesy($this->image);
        $testRangeStart = "2";
        $testRangeEnd = round($rowCount / 2);
        $testRow = round($testRangeEnd/2);
        $firstRowKeyIsIn = null;

        while($firstRowKeyIsIn === null)
        {
            $result = $this->rowKeyStartPosition($testRow);

            if($result !== null) //hits key
            {
                $testRangeEnd = $testRow;
                $testRow = (int)$testRangeStart + round(($testRangeEnd-$testRangeStart) / 2);
            }
            else //misses key
            {
                $testRangeStart = $testRow;
                $testRow = $testRangeStart + round(($testRangeEnd-$testRangeStart) / 2);
            }

            if($testRangeEnd - $testRangeStart < 2)
            {
                $firstRowKeyIsIn = $testRow;
            }
        }

        return $firstRowKeyIsIn;
    }

    private function rowKeyEndPosition($row)
    {
        $image = $this->image;
        $columnCount = $this->columnCount;
        $keyEndPosition = null;

        imageflip($image, IMG_FLIP_HORIZONTAL);
        $keyStartPosition = $this->rowKeyStartPosition($row);
        imageflip($image, IMG_FLIP_HORIZONTAL);

        if($keyStartPosition !== null)
        {
            $keyEndPosition = $columnCount-1 - $keyStartPosition;
        }

        return $keyEndPosition;
    }

    private function rowKeyStartPosition($row)
    {
        $image = $this->image;
        $columnCount = imagesx($image);
        $keyColorLowValue = $this->keyColorLowValue;
        $keyColorHighValue = $this->keyColorHighValue;
        $sampleRow = $row;
        $keyStartPosition = null;

        $this->validateIsNumericGreaterThanZero($keyColorLowValue, "keyColorLowValue");
        $this->validateIsNumericGreaterThanZero($keyColorHighValue, "keyColorHighValue");

        for($column = 0; $column < $columnCount; $column++)
        {
            $color = imagecolorat($image, $column, $sampleRow);

            if($color > $keyColorLowValue && $color < $keyColorHighValue)
            {
                $noiseDetected = $this->noiseDetection($image, $column, $sampleRow);
                if($noiseDetected === 0)
                {
                    $keyStartPosition = $column;
                    break;
                }
                else
                {
                    $keyStartPosition = null;
                }
            }
        }

        return $keyStartPosition;
    }

    private function noiseDetection($image, $column, $row)
    {
        $keyColorLowValue = $this->keyColorLowValue;
        $keyColorHighValue = $this->keyColorHighValue;
        $colors = array();
        $noiseDetected = 0;
        $noiseDetectionRange = $this->findNoiseDetectionRange();
        $consecutiveColorCountThreshold = $noiseDetectionRange/2;

        //check next 50 cols and confirm does not go back to consecutive base colors
        for($i=$column; $i<$column+$noiseDetectionRange; $i++)
        {
            $colors[] = imagecolorat($image, $i, $row);
        }

        //check if there are 20+ consecutive non key colors
        $consecutiveColorCount = 0;
        foreach($colors as $color)
        {
            if($color < $keyColorLowValue || $color > $keyColorHighValue)
            {
                //is backgroundcolor
                $consecutiveColorCount++;
            }
            else
            {
                //is keycolor
                $consecutiveColorCount = 0;
            }

            if($consecutiveColorCount >= $consecutiveColorCountThreshold)
            {
                //noise detected
                $noiseDetected = 1;
                break;
            }
        }

        return $noiseDetected;
    }

    private function findNoiseDetectionRange()
    {
        $image = $this->image;
        $noiseScale = $this->noiseScale;
        $imageColorSample = $this->sampleImageColor();
        $percentOfImageIsKey = $this->analyzeImageColorSample($imageColorSample);
        $imageColCount = imagesx($image);

        $noiseDetectionRange = round($imageColCount * $percentOfImageIsKey * $noiseScale);

        return $noiseDetectionRange;
    }

    private function analyzeImageColorSample($imageColorSample)
    {
        $keyColorHighValue = $this->keyColorHighValue;
        $keyColorLowValue = $this->keyColorLowValue;
        $sampleCount = count($imageColorSample);
        $keyColorCount = 0;

        for($i=0; $i<$sampleCount; $i++)
        {
            $color = $imageColorSample[$i];
            if($color >= $keyColorLowValue && $color <= $keyColorHighValue)
            {
                $keyColorCount++;
            }
        }

        $percentOfImageIsKey = $keyColorCount / $sampleCount;

        return $percentOfImageIsKey;
    }

    private function sampleImageColor()
    {
        $image = $this->image;
        $minColSampleCount = $this->minColSampleCount;
        $sampleAmount = $this->sampleAmount;
        $colCount = imagesx($image);
        $rowCount = imagesy($image);
        $colSampleCount = floor($colCount*$sampleAmount);

        if($colSampleCount < $minColSampleCount)
        {
            $colSampleCount = $minColSampleCount;
        }

        $jumps = floor($colCount/$colSampleCount);
        $imageColorSample = null;

        for($col=1; $col<$colCount; $col+=$jumps)
        {
            for($row=1; $row<$rowCount; $row+=$jumps)
            {
                $color = imagecolorat($image, $col, $row);
                $imageColorSample[] = $color;
            }
        }

        return $imageColorSample;
    }

    private function setKeyColorRange()
    {
        //background is either 0 or 16777215 after new filter
        $imageStats = $this->imageStats;
        $backgroundColorThreshold = $this->backgroundColorThreshold;
        $backgroundColorAverage = $imageStats['meta']['avgAverages'];
        $colorSpectrumMiddle = round(16777215/2);

        if($backgroundColorAverage < $colorSpectrumMiddle)
        {
            $this->keyColorLowValue = $backgroundColorAverage + $backgroundColorThreshold;
            $this->keyColorHighValue = 16777215 - $backgroundColorThreshold;
        }

        if ($backgroundColorAverage >= $colorSpectrumMiddle)
        {
            $this->keyColorLowValue = 0 + $backgroundColorThreshold;
            $this->keyColorHighValue = $backgroundColorAverage - $backgroundColorThreshold;
        }
    }

//todo key type detection (height width ratio)
//todo autocrop
//todo shadow detection

    private function validateIsNumericGreaterThanZero($propertyValue, $propertyName)
    {
        if(!is_numeric($propertyValue) || $propertyValue < 0)
        {
            throw new ExceptionKeyDecoder("$propertyName is not set properly");
        }
    }
}
