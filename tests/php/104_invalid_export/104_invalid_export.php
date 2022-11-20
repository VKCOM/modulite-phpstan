@kphp_should_fail
KPHP_ENABLE_MODULITE=1
/'require' contains a member of @utils; you should require @utils directly, not its members/
/'export' contains '\\AAA104' that does not belong to @algo/
/Failed loading Utils104\/.modulite.yaml:/
/'force-internal' contains '@algo' which is exported/
/'force-internal' can contain only class members/
/'force-internal' contains '\\AAA104::ONE' that does not belong to @utils/
/'export' of @utils lists a non-child @algo/
/'export' of @utils lists a non-child @utils\/inner\/very/
<?php

class AAA104 {
    const ONE = 1;
}

Utils104\Strings104::doSmth();
Algo104\Sort104::doSmth();
Utils104\UtilsInner\UtilsVeryInner\UtilsVeryInnerFuncs::doSmth();
