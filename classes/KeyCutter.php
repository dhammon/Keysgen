<?php
require_once __DIR__ . "/../config/Config.php";
require_once Config::$pathExceptionKeyCutter;

class KeyCutter
{
    private $keyCode;                                                   //key or bitting code
    private $keyCodeArray;
    private $landingWidth;                                              //mm; where cylinder pin touches key
    private $shoulderToFirstCut;                                        //mm; from end of collar to first padding center
    private $cylinderSpacing;                                           //mm; landing width to landing width (center on center)
    private $cylinderCount;                                             //each
    private $depthSpacing;                                              //mm; space between each depth level
    private $depthLevels;                                               //each; kwikset=7
    private $depthFirstLevel;                                           //each; 0 or 1; kwikset=1
    private $bladeHeight;                                               //mm; blade bottom to top(uncut)
    private $bladeLowerHeight;                                          //mm; blade bottom to bottom of top
    private $bladeUpperHeight;                                          //mm; top of blade bottom to top of blade top
    private $bladeUpperOriginY;                                         //mm; bottom left corner of blade upper first point
    private $bladeUpperOriginX;                                         //mm; bottom left corner of blade upper first point
    private $bladeUpperOriginZ;                                         //mm; bottom left corner of blade upper first point
    private $keyProfileSetting = 0;
    private $bladeWidth;                                                //mm; ie width of very top of key hole; biting width
    private $bladeTipLength;                                            //mm; from end of last landing to tip of blade
    private $betweenLandingAngle;                                       //ratio; rise over run
    private $minimumAdjacentCutSpecification;                           //macs; max landing depth difference
    private $shaftPath;                                                 //path to shaft.stl

    public function setKeyProfile(array $keyProfile, $keyCode)
    {
        $this->validateKeyCode($keyCode);
        $this->keyCode = $keyCode;
        $this->keyCodeArray = str_split($this->keyCode);
        $this->cylinderCount = count($this->keyCodeArray);

        $this->validateKeyProfile($keyProfile);

        $this->landingWidth = $keyProfile['landingWidth'];
        $this->shoulderToFirstCut = $keyProfile['shoulderToFirstCut'];
        $this->cylinderSpacing = $keyProfile['cylinderSpacing'];
        $this->depthSpacing = $keyProfile['depthSpacing'];
        $this->depthLevels = $keyProfile['depthLevels'];
        $this->depthFirstLevel = $keyProfile['depthFirstLevel'];
        $this->bladeHeight = $keyProfile['bladeHeight'];
        $this->bladeLowerHeight = $keyProfile['bladeLowerHeight'];
        $this->bladeUpperOriginY = $keyProfile['bladeUpperOriginY'];
        $this->bladeUpperOriginX = $keyProfile['bladeUpperOriginX'];
        $this->bladeUpperOriginZ = $keyProfile['bladeUpperOriginZ'];
        $this->bladeWidth = $keyProfile['bladeWidth'];
        $this->bladeTipLength = $keyProfile['bladeTipLength'];
        $this->betweenLandingAngle = $keyProfile['betweenLandingAngle'];
        $this->minimumAdjacentCutSpecification = $keyProfile['minimumAdjacentCutSpecification'];
        $this->shaftPath = $keyProfile['shaftPath'];

        $this->validateKeyCodeDepthLevelCompliance($this->keyCodeArray);
        $this->bladeUpperHeight = $this->bladeHeight - $this->bladeLowerHeight;
        $this->keyProfileSetting = 1;
    }

    public function cutKey()
    {
        $this->validateKeyProfileSet();
        $this->validateMinimumAdjacentCutSpecification($this->keyCodeArray);

        $triangles = $this->buildLandings();
        $triangles = $this->buildBetweenLandings($triangles);
        $triangles = $this->buildTip($triangles);
        $triangles = $this->buildShoulder($triangles);
        $triangles = $this->buildUnderLandings($triangles);
        $triangles = $this->buildWalls($triangles);
        $triangles = $this->buildTop($triangles);

        return $triangles;
    }

    private function buildTop(array $triangles)
    {
        $newTriangles = null;

        for($i=0; $i<count($triangles); $i++)
        {
            $vertex1y = $triangles[$i][0][0];
            $vertex1x = $triangles[$i][0][1];
            $vertex1z = $this->bladeUpperOriginZ + $this->bladeWidth;
            $vertex2y = $triangles[$i][1][0];
            $vertex2x = $triangles[$i][1][1];
            $vertex2z = $this->bladeUpperOriginZ + $this->bladeWidth;
            $vertex3y = $triangles[$i][2][0];
            $vertex3x = $triangles[$i][2][1];
            $vertex3z = $this->bladeUpperOriginZ + $this->bladeWidth;

            $newTriangles[] = array(
                array($vertex1y, $vertex1x, $vertex1z),
                array($vertex3y, $vertex3x, $vertex3z),
                array($vertex2y, $vertex2x, $vertex2z)
            );
        }

        return array_merge($triangles, $newTriangles);
    }

    private function buildWalls(array $triangles)
    {
        $topWalls = $this->buildTopWall($triangles);
        $bottomWalls = $this->buildBottomWall($triangles);
        $newTriangles = array_merge($topWalls, $bottomWalls);

        $sideWall = $this->buildSideWall();
        $newTriangles = array_merge($newTriangles, $sideWall);

        return array_merge($triangles, $newTriangles);
    }

    private function buildSideWall()
    {
        $z = $this->bladeWidth;

        $vertex1y = -1 * $this->bladeUpperOriginY;
        $vertex1x = $this->bladeUpperOriginX;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = -1 * ($this->bladeUpperHeight + $this->bladeUpperOriginY);
        $vertex2x = $this->bladeUpperOriginX;
        $vertex2z = $this->bladeUpperOriginZ + $z;
        $vertex3y = -1 * ($this->bladeUpperHeight + $this->bladeUpperOriginY);
        $vertex3x = $this->bladeUpperOriginX;
        $vertex3z = $this->bladeUpperOriginZ;
        $newTriangles[] = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );

        $vertex1y = -1 * $this->bladeUpperOriginY;
        $vertex1x = $this->bladeUpperOriginX;
        $vertex1z = $this->bladeUpperOriginZ + $z;
        $vertex2y = -1 * ($this->bladeUpperHeight + $this->bladeUpperOriginY);
        $vertex2x = $this->bladeUpperOriginX;
        $vertex2z = $this->bladeUpperOriginZ + $z;
        $vertex3y = -1 * $this->bladeUpperOriginY;
        $vertex3x = $this->bladeUpperOriginX;
        $vertex3z = $this->bladeUpperOriginZ;
        $newTriangles[] = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );

        return $newTriangles;
    }

    private function buildBottomWall(array $triangles)
    {
        $z = $this->bladeWidth;
        $newTriangles = null;
        $count = count($triangles);

        for($i=$count/2; $i<$count; $i++)
        {
            $vertex1y = $triangles[$i][0][0];
            $vertex1x = $triangles[$i][0][1];
            $vertex1z = $this->bladeUpperOriginZ;
            $vertex2y = $triangles[$i][0][0];
            $vertex2x = $triangles[$i][0][1];
            $vertex2z = $this->bladeUpperOriginZ + $z;
            $vertex3y = $triangles[$i][2][0];
            $vertex3x = $triangles[$i][2][1];
            $vertex3z = $this->bladeUpperOriginZ;
            $newTriangles[] = array(
                array($vertex1y, $vertex1x, $vertex1z),
                array($vertex2y, $vertex2x, $vertex2z),
                array($vertex3y, $vertex3x, $vertex3z)
            );

            $vertex1y = $triangles[$i][0][0];
            $vertex1x = $triangles[$i][0][1];
            $vertex1z = $this->bladeUpperOriginZ + $z;
            $vertex2y = $triangles[$i][2][0];
            $vertex2x = $triangles[$i][2][1];
            $vertex2z = $this->bladeUpperOriginZ + $z;
            $vertex3y = $triangles[$i][2][0];
            $vertex3x = $triangles[$i][2][1];
            $vertex3z = $this->bladeUpperOriginZ;
            $newTriangles[] = array(
                array($vertex1y, $vertex1x, $vertex1z),
                array($vertex3y, $vertex3x, $vertex3z),
                array($vertex2y, $vertex2x, $vertex2z),
            );
        }
        return $newTriangles;
    }

    private function buildTopWall(array $triangles)
    {
        $z = $this->bladeWidth;
        $newTriangles = null;
        $count = count($triangles)/2;

        for($i=0; $i<$count; $i++)
        {
            $vertex1y = $triangles[$i][1][0];
            $vertex1x = $triangles[$i][1][1];
            $vertex1z = $this->bladeUpperOriginZ;
            $vertex2y = $triangles[$i][1][0];
            $vertex2x = $triangles[$i][1][1];
            $vertex2z = $this->bladeUpperOriginZ + $z;
            $vertex3y = $triangles[$i][2][0];
            $vertex3x = $triangles[$i][2][1];
            $vertex3z = $this->bladeUpperOriginZ;
            $newTriangles[] = array(
                array($vertex1y, $vertex1x, $vertex1z),
                array($vertex2y, $vertex2x, $vertex2z),
                array($vertex3y, $vertex3x, $vertex3z),
            );

            $vertex1y = $triangles[$i][2][0];
            $vertex1x = $triangles[$i][2][1];
            $vertex1z = $this->bladeUpperOriginZ + $z;
            $vertex2y = $triangles[$i][1][0];
            $vertex2x = $triangles[$i][1][1];
            $vertex2z = $this->bladeUpperOriginZ + $z;
            $vertex3y = $triangles[$i][2][0];
            $vertex3x = $triangles[$i][2][1];
            $vertex3z = $this->bladeUpperOriginZ;
            $newTriangles[] = array(
                array($vertex1y, $vertex1x, $vertex1z),
                array($vertex3y, $vertex3x, $vertex3z),
                array($vertex2y, $vertex2x, $vertex2z),
            );
        }

        return $newTriangles;
    }

    private function buildUnderLandings(array $triangles)
    {
        $newTriangles = null;

        for($i=0; $i<count($triangles); $i++)
        {
            $vertex1y = $triangles[$i][0][0];
            $vertex1x = $triangles[$i][0][1];
            $vertex1z = $this->bladeUpperOriginZ;
            $vertex2y = $triangles[$i][2][0];
            $vertex2x = $triangles[$i][2][1];
            $vertex2z = $this->bladeUpperOriginZ;
            $vertex3y = $triangles[$i][0][0];
            $vertex3x = $triangles[$i][2][1];
            $vertex3z = $this->bladeUpperOriginZ;

            $newTriangles[] = array(
                array($vertex1y, $vertex1x, $vertex1z),
                array($vertex2y, $vertex2x, $vertex2z),
                array($vertex3y, $vertex3x, $vertex3z)
            );
        }

        return array_merge($triangles, $newTriangles);
    }

    private function buildShoulder(array $triangles)
    {
        $shoulderX = $this->bladeUpperOriginX;
        $shoulderY = -1 * ($this->bladeUpperHeight + $this->bladeUpperOriginY);
        $shoulderSlope = -0;

        $firstLandingX = $triangles[0][1][1];
        $firstLandingY = $triangles[0][1][0];
        $backSlashSlope = -1 * $this->betweenLandingAngle;

        $currentLandingYIntercept = -1*$this->calculateYIntercept($shoulderX, -1*$shoulderY, $shoulderSlope);
        $nextLandingYIntercept = $this->calculateYIntercept($firstLandingX, -1*$firstLandingY, $backSlashSlope);
        $intersectX = -1*$this->findIntersectX($shoulderSlope, $currentLandingYIntercept, $backSlashSlope, -$nextLandingYIntercept);
        $intersectY = $this->findIntersectY($shoulderSlope, $currentLandingYIntercept, $intersectX);

        $vertex1y = $triangles[0][0][0];
        $vertex1x = $this->bladeUpperOriginX;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = $shoulderY;
        $vertex2x = $shoulderX;
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = $intersectY;
        $vertex3x = $intersectX;
        $vertex3z = $this->bladeUpperOriginZ;
        $shoulder = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );
        $newTriangles[] = $shoulder;

        //tooth
        $vertex1y = -1*$this->bladeUpperOriginY;
        $vertex1x = $intersectX;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = $intersectY;
        $vertex2x = $intersectX;
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = $firstLandingY;
        $vertex3x = $firstLandingX;
        $vertex3z = $this->bladeUpperOriginZ;
        $tooth = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );
        $newTriangles[] = $tooth;

        return array_merge($triangles, $newTriangles);
    }

    private function buildTip(array $triangles)
    {
        $lastLandingPosition = ($this->cylinderCount)*2-1;
        $startPosition = array($triangles[$lastLandingPosition][2][1], $triangles[$lastLandingPosition][2][0]);
        $endPosition = array($triangles[$lastLandingPosition][2][1] + $this->bladeTipLength, $triangles[$lastLandingPosition][0][0]);
        $firstVertexY = $triangles[$lastLandingPosition][0][0];

        $tooth = $this->prepareBetweenLandings($startPosition, $endPosition, $firstVertexY);
        $firstToothHalf = $tooth[0];
        $secondToothHalf = $tooth[1];
        $newTriangles[] = $firstToothHalf;
        $newTriangles[] = $secondToothHalf;

        return array_merge($triangles, $newTriangles);
    }

    private function buildBetweenLandings(array $triangles)
    {
        $newTriangles = null;

        for($i=0; $i<($this->cylinderCount-1)*2; $i++)
        {
            if(($i % 2) == 1)
            {
                $startPosition = array($triangles[$i][2][1], $triangles[$i][2][0]);
                $endPosition = array($triangles[$i+1][1][1], $triangles[$i+1][1][0]);
                $firstVertexY = $triangles[$i][0][0];

                $tooth = $this->prepareBetweenLandings($startPosition, $endPosition, $firstVertexY);
                $firstToothHalf = $tooth[0];
                $secondToothHalf = $tooth[1];
                $newTriangles[] = $firstToothHalf;
                $newTriangles[] = $secondToothHalf;
            }
        }

        return array_merge($triangles, $newTriangles);
    }

    private function prepareBetweenLandings(array $startPosition, array $endPosition, $firstVertexY)
    {
        $currentLandingX = $startPosition[0];
        $currentLandingY = $startPosition[1];
        $nextLandingX = $endPosition[0];
        $nextLandingY = $endPosition[1];
        $forwardSlashSlope = $this->betweenLandingAngle;
        $backSlashSlope = -1 * $this->betweenLandingAngle;
        $currentLandingYIntercept = $this->calculateYIntercept($currentLandingX, -1 * $currentLandingY, $forwardSlashSlope);
        $nextLandingYIntercept = $this->calculateYIntercept($nextLandingX, -1 * $nextLandingY, $backSlashSlope);
        $intersectX = $this->findIntersectX($forwardSlashSlope, $currentLandingYIntercept, $backSlashSlope, $nextLandingYIntercept);
        $intersectY = $this->findIntersectY($forwardSlashSlope, $currentLandingYIntercept, $intersectX);

        //current landing
        $vertex1y = $firstVertexY;
        $vertex1x = $currentLandingX;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = $currentLandingY;
        $vertex2x = $currentLandingX;
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = -1 * $intersectY;
        $vertex3x = $intersectX;
        $vertex3z = $this->bladeUpperOriginZ;
        $firstHalfBetweenLandings = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );
        $tooth[] = $firstHalfBetweenLandings;

        //next landing
        $vertex1y = $firstVertexY;
        $vertex1x = $intersectX;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = -1 * $intersectY;
        $vertex2x = $intersectX;
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = $nextLandingY;
        $vertex3x = $nextLandingX;
        $vertex3z = $this->bladeUpperOriginZ;
        $secondHalfBetweenLandings = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );
        $tooth[] = $secondHalfBetweenLandings;

        return $tooth;
    }

    private function findIntersectY($lineOneSlope, $lineOneYIntercept, $intersectX)
    {
        $intersectY = $lineOneSlope * $intersectX + $lineOneYIntercept;

        return $intersectY;
    }

    private function findIntersectX($lineOneSlope, $lineOneYIntercept, $lineTwoSlope, $lineTwoYIntercept)
    {
        if($lineOneSlope >= 0)
        {
            if($lineTwoYIntercept >= 0)
            {
                $intersectX = ($lineOneYIntercept - $lineTwoYIntercept) / ($lineTwoSlope - $lineOneSlope);
            }
            else
            {
                $intersectX = ($lineOneYIntercept + -1 * $lineTwoYIntercept) / ($lineTwoSlope - $lineOneSlope);
            }
        }
        else
        {
            if($lineTwoYIntercept >= 0)
            {
                $intersectX = ($lineOneYIntercept - $lineTwoYIntercept) / ($lineTwoSlope + (-1 * $lineOneSlope));
            }
            else
            {
                $intersectX = ($lineOneYIntercept + -1 * $lineTwoYIntercept) / ($lineTwoSlope + (-1 * $lineOneSlope));
            }
        }

        return $intersectX;
    }

    private function calculateYIntercept($x, $y, $slope)
    {
        $product = $slope * $x;

        if($product >= 0)
        {
            $yIntercept = $y - $product;
        }
        else
        {
            $yIntercept = $y + (-1 * $product);
        }

        return $yIntercept;
    }

    private function buildLandings()
    {
        $triangles = array();
        $xAxisStart = $this->bladeUpperOriginX + $this->shoulderToFirstCut;

        for($i=0; $i<$this->cylinderCount; $i++)
        {
            if($i==0)
            {
                $newTriangles = $this->prepareFirstHalfOfFirstLanding($xAxisStart, $i);
                $triangles = array_merge($triangles, $newTriangles);
            }

            if($i>0 && $i != $this->cylinderCount)
            {
                $newTriangles = $this->prepareMiddleLandings($xAxisStart, $i);
                $triangles = array_merge($triangles, $newTriangles);
            }

            if($i == $this->cylinderCount-1)
            {
                $newTriangles = $this->prepareSecondHalfOfLastLanding($xAxisStart, $i);
                $triangles = array_merge($triangles, $newTriangles);
            }
        }

        return $triangles;
    }

    private function prepareSecondHalfOfLastLanding($xAxisStart, $keyCodeArrayPosition)
    {
        $currentLandingLevel = $this->findLandingLevel($keyCodeArrayPosition);

        $vertex1y = -1 * $this->bladeUpperOriginY;
        $vertex1x = $xAxisStart + $this->cylinderSpacing * $keyCodeArrayPosition;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = $currentLandingLevel;
        $vertex2x = $xAxisStart + $this->cylinderSpacing * $keyCodeArrayPosition;
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = $currentLandingLevel;
        $vertex3x = $xAxisStart + $this->cylinderSpacing * $keyCodeArrayPosition + $this->landingWidth/2;
        $vertex3z = $this->bladeUpperOriginZ;

        $triangles[] = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );

        return $triangles;
    }

    private function prepareMiddleLandings($xAxisStart, $keyCodeArrayPosition)
    {
        $currentLandingLevel = $this->findLandingLevel($keyCodeArrayPosition-1);
        $nextLandingLevel = $this->findLandingLevel($keyCodeArrayPosition);

        $currX = $this->bladeUpperOriginX + $this->shoulderToFirstCut + $this->cylinderSpacing * ($keyCodeArrayPosition-1) + ($this->landingWidth/2);
        $currY = $currentLandingLevel;
        $nextX = $this->bladeUpperOriginX + $this->shoulderToFirstCut + $this->cylinderSpacing * ($keyCodeArrayPosition) - $this->landingWidth/2;
        $nextY = $nextLandingLevel;

        $landingPositions = $this->findLandingPositions($currY, $currX, $nextY, $nextX);
        $currentLandingY = $landingPositions[0];
        $currentLandingX = $landingPositions[1];
        $nextLandingY = $landingPositions[2];
        $nextLandingX = $landingPositions[3];

        $vertex1y = -1 * $this->bladeUpperOriginY;
        $vertex1x = $xAxisStart + $this->cylinderSpacing * ($keyCodeArrayPosition-1);
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = $currentLandingY;
        $vertex2x = $xAxisStart + $this->cylinderSpacing * ($keyCodeArrayPosition-1);
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = $currentLandingY;
        $vertex3x = $currentLandingX;
        $vertex3z = $this->bladeUpperOriginZ;

        $triangles[] = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );

        $nextVertex1y = -1 * $this->bladeUpperOriginY;
        $nextVertex1x = $nextLandingX;
        $nextVertex1z = $this->bladeUpperOriginZ;
        $nextVertex2y = $nextLandingY;
        $nextVertex2x = $nextLandingX;
        $nextVertex2z = $this->bladeUpperOriginZ;
        $nextVertex3y = $nextLandingY;
        $nextVertex3x = $xAxisStart + $this->cylinderSpacing * ($keyCodeArrayPosition);
        $nextVertex3z = $this->bladeUpperOriginZ;

        $triangles[] = array(
            array($nextVertex1y, $nextVertex1x, $nextVertex1z),
            array($nextVertex2y, $nextVertex2x, $nextVertex2z),
            array($nextVertex3y, $nextVertex3x, $nextVertex3z)
        );

        return $triangles;
    }

    private function prepareFirstHalfOfFirstLanding($xAxisStart, $keyCodeArrayPosition)
    {
        $currentLandingLevel = $this->findLandingLevel($keyCodeArrayPosition);

        $vertex1y = -1 * $this->bladeUpperOriginY;
        $vertex1x = $xAxisStart - $this->landingWidth / 2;
        $vertex1z = $this->bladeUpperOriginZ;
        $vertex2y = $currentLandingLevel;
        $vertex2x = $xAxisStart - $this->landingWidth / 2;
        $vertex2z = $this->bladeUpperOriginZ;
        $vertex3y = $currentLandingLevel;
        $vertex3x = $xAxisStart;
        $vertex3z = $this->bladeUpperOriginZ;

        $triangles[] = array(
            array($vertex1y, $vertex1x, $vertex1z),
            array($vertex2y, $vertex2x, $vertex2z),
            array($vertex3y, $vertex3x, $vertex3z)
        );

        return $triangles;
    }

    private function findLandingLevel($keyCodeArrayPosition)
    {
        $levelAdjustment = $this->findDepthLevelAdjustment();
        $rootDepth = ($levelAdjustment + $this->keyCodeArray[$keyCodeArrayPosition]) * $this->depthSpacing;
        $landingLevel = -1*($this->bladeUpperOriginY + $this->bladeUpperHeight - $rootDepth);

        return $landingLevel;
    }

    private function findLandingPositions($currentY, $currentX, $nextY, $nextX)
    {
        $landingPositions = null;
        $forwardSlashSlope = $this->betweenLandingAngle;
        $backSlashSlope = -1 * $this->betweenLandingAngle;
        $distanceBetweenLandings = $currentY - $nextY;
        $currentLandingYX = array($currentY, $currentX);
        $nextLandingYX = array($nextY, $nextX);
        $landingPositions = array_merge($currentLandingYX, $nextLandingYX);

        if($currentY <= $nextY)        //< because Y axis is negative format
        {
            $proposedX = $nextX - $distanceBetweenLandings / $backSlashSlope;

            if($currentX >= $proposedX)
            {
                $currentLandingYX = array($currentY, $proposedX);
                $nextLandingYX = array($nextY, $nextX);
                $landingPositions = array_merge($currentLandingYX, $nextLandingYX);
            }
        }

        if($currentY > $nextY)        //> because Y axis is negative format
        {
            $proposedX = $distanceBetweenLandings / $forwardSlashSlope + $currentX;

            if($nextX <= $proposedX)
            {
                $currentLandingYX = array($currentY, $currentX);
                $nextLandingYX = array($nextY, $proposedX);
                $landingPositions = array_merge($currentLandingYX, $nextLandingYX);
            }
        }

        return $landingPositions;
    }

    private function findDepthLevelAdjustment()
    {
        if($this->depthFirstLevel == 0)
        {
            $levelAdjustment = 1;
        }
        else
        {
            $levelAdjustment = 0;
        }

        return $levelAdjustment;
    }

    private function validateMinimumAdjacentCutSpecification(array $keyCodeArray)
    {
        $codeCount = count($keyCodeArray);
        $minimumAdjacentCutSpecification = $this->minimumAdjacentCutSpecification;

        for($i=0; $i<$codeCount-1; $i++)
        {
            $firstCode = $keyCodeArray[$i];
            $secondCode = $keyCodeArray[$i+1];
            $codeDifference = $firstCode - $secondCode;
            $absoluteCodeDifference = abs($codeDifference);

            if($absoluteCodeDifference > $minimumAdjacentCutSpecification)
            {
                throw new ExceptionKeyCutter("Minimum Adjacent Cut Specification (MACS) between $firstCode and $secondCode violated");
            }
        }
    }

    private function validateKeyProfileSet()
    {
        if($this->keyProfileSetting !== 1)
        {
            throw new ExceptionKeyCutter("Key ProfileTest not set");
        }
    }

    private function validateKeyCodeDepthLevelCompliance($keyCodeArray)
    {
        foreach($keyCodeArray as $code)
        {
            if($code > $this->depthLevels || $code < $this->depthFirstLevel)
            {
                throw new ExceptionKeyCutter("$code in key code outside of allowed depth levels.");
            }
        }
    }

    private function validateKeyProfile($keyProfile)
    {
        $this->validateAcceptableArrayKeys($keyProfile);
        $this->validateArrayValuesNumeric($keyProfile);
        $this->validateArrayValueStringLength($keyProfile);
    }

    private function validateArrayValueStringLength($keyProfile)
    {
        foreach ($keyProfile as $value)
        {
            if(is_string($value))
            {
                if(strlen($value) > 80)
                {
                    throw new ExceptionKeyCutter("Key profile string value greater than 80");
                }
            }
        }
    }

    private function validateAcceptableArrayKeys($keyProfile)
    {
        $acceptableArray = array(
            'id' => 0,
            'profileName' => "nameOfProfile",
            'landingWidth' => 1,
            'shoulderToFirstCut' => 2,
            'cylinderSpacing' => 3,
            'depthSpacing' => 4,
            'depthLevels' => 9,
            'depthFirstLevel' => 1,
            'bladeHeight' => 8,
            'bladeLowerHeight' => 9,
            'bladeUpperOriginY' => 10,
            'bladeUpperOriginX' => 11,
            'bladeUpperOriginZ' =>12,
            'bladeWidth' => 1.15,
            'bladeTipLength' => 4,
            'betweenLandingAngle' => 1.428,
            'minimumAdjacentCutSpecification' => 4,
            'shaftPath' => "/path/to/shaft.stl",
            'keyLength' => 52,
            'bowToShoulderDistance' => 24,
            'cylinderCount' => 5
        );

        $keyProfileMissing = array_diff_key($acceptableArray, $keyProfile);
        if(!empty($keyProfileMissing))
        {
            throw new ExceptionKeyCutter("Key profile missing associative keys");
        }

        $keyProfileExtra = array_diff_key($keyProfile, $acceptableArray);
        if(!empty($keyProfileExtra))
        {
            throw new ExceptionKeyCutter("Key profile has extra associative keys");
        }
    }

    private function validateArrayValuesNumeric($keyProfile)
    {
        foreach($keyProfile as $keyName => $profile)
        {
            if(!is_numeric($profile) && $keyName != "shaftPath" && $keyName != "profileName")
            {
                throw new ExceptionKeyCutter("$keyName in keyProfile must be numeric");
            }
        }
    }

    private function validateKeyCode($keyCode)
    {
        if(!is_numeric($keyCode) || $keyCode < 0)
        {
            throw new ExceptionKeyCutter("Key Code must be an integer greater than 0");
        }

        if(strlen($keyCode) > 20)
        {
            throw new ExceptionKeyCutter("Key Code must be less than 20 characters");
        }
    }
}
