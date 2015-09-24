<?php

use Propel\Runtime\ActiveQuery\Criteria;

use UWDOEM\Framework\Filter\FilterStatement;
use UWDOEM\Framework\Filter\FilterBuilder;
use UWDOEM\Framework\Filter\Filter;
use UWDOEM\Framework\Etc\Settings;
use UWDOEMTest\TestClassQuery;


class FilterTest extends PHPUnit_Framework_TestCase {

    protected $conditions = [
        FilterStatement::COND_SORT_ASC,
        FilterStatement::COND_SORT_DESC,
        FilterStatement::COND_LESS_THAN,
        FilterStatement::COND_GREATER_THAN,
        FilterStatement::COND_EQUAL_TO,
        FilterStatement::COND_NOT_EQUAL_TO,
        FilterStatement::COND_PAGINATE_BY,
        FilterStatement::COND_TRUTHY,
        FilterStatement::COND_FALSEY,
    ];


    public function testFilterStatement() {
        $fieldName = (string)rand();
        $condition = (string)rand();
        $criterion = (string)rand();

        $statement = new FilterStatement($fieldName, $condition, $criterion, null);

        $this->assertEquals($fieldName, $statement->getFieldName());
        $this->assertEquals($condition, $statement->getCondition());
        $this->assertEquals($criterion, $statement->getCriterion());
    }

    public function testBuildStaticFilter() {

        $fieldName = (string)rand();
        $condition = array_rand(array_flip($this->conditions), 1);
        $criterion = (string)rand();
        $handle = (string)rand();

        $filter = FilterBuilder::begin()
            ->setType(Filter::TYPE_STATIC)
            ->setHandle($handle)
            ->setFieldName($fieldName)
            ->setCondition($condition)
            ->setCriterion($criterion)
            ->build();

        $this->assertEquals($handle, $filter->getHandle());
        $this->assertEquals(1, sizeof($filter->getStatements()));

        $statement = $filter->getStatements()[0];

        $this->assertEquals($fieldName, $statement->getFieldName());
        $this->assertEquals($condition, $statement->getCondition());
        $this->assertEquals($criterion, $statement->getCriterion());
    }

    public function testBuildPaginationFilter() {
        $maxPerPage = rand();
        $page = rand();
        $handle = (string)rand();
        $type = Filter::TYPE_PAGINATION;

        $filter = FilterBuilder::begin()
            ->setType($type)
            ->setHandle($handle)
            ->setPage($page)
            ->setMaxPerPage($maxPerPage)
            ->build();

        $this->assertEquals($handle, $filter->getHandle());
        $this->assertEquals($type, $filter->getType());
        $this->assertEquals(1, sizeof($filter->getStatements()));

        $statement = $filter->getStatements()[0];

        // Assert that the filter statement was created correctly
        $this->assertEquals(FilterStatement::COND_PAGINATE_BY, $statement->getCondition());
        $this->assertEquals($maxPerPage, $statement->getCriterion());
        $this->assertEquals($page, $statement->getControl());
    }

    public function testBuildPaginationFilterUsesPaginateSetting() {
        $paginateBy = rand();
        $handle = (string)rand();
        $type = Filter::TYPE_PAGINATION;

        // Store the current default pagination
        $defaultPagination = Settings::getDefaultPagination();

        // Set our new pagination
        Settings::setDefaultPagination($paginateBy);

        $filter = FilterBuilder::begin()
            ->setType($type)
            ->setHandle($handle)
            ->build();

        $this->assertEquals(1, sizeof($filter->getStatements()));

        $this->assertEquals($handle, $filter->getHandle());
        $this->assertEquals($type, $filter->getType());
        $statement = $filter->getStatements()[0];

        $this->assertEquals(FilterStatement::COND_PAGINATE_BY, $statement->getCondition());
        $this->assertEquals($paginateBy, $statement->getCriterion());

        // Return the old default pagination
        Settings::setDefaultPagination($defaultPagination);
    }

    /**
     * @expectedException              Exception
     * @expectedExceptionMessageRegExp #You must set _handle.*#
     */
    public function testBuildFilterErrorWithoutHandle() {
        $filter = FilterBuilder::begin()
            ->setType(Filter::TYPE_PAGINATION)
            ->build();
    }

    public function testPaginationFilterOptions() {
        $maxPerPage = rand(5, 15);
        $count = rand(50, 200);

        $filter = FilterBuilder::begin()
            ->setHandle("pagination")
            ->setMaxPerPage($maxPerPage)
            ->setType(Filter::TYPE_PAGINATION)
            ->build();

        $query = new MockQuery();
        $query->count = $count;

        $filter->queryFilter($query);

        $expectedNumPages = ceil($count/$maxPerPage);

        $this->assertEquals(range(1, $expectedNumPages), $filter->getOptions());
    }

    public function testBuildFilterWithNextFilter() {
        $filter1 = FilterBuilder::begin()
            ->setHandle("Filter1")
            ->setType(Filter::TYPE_PAGINATION)
            ->build();

        $filter2 = FilterBuilder::begin()
            ->setHandle("Filter2")
            ->setNextFilter($filter1)
            ->setType(Filter::TYPE_PAGINATION)
            ->build();

        $this->assertEquals($filter1, $filter2->getNextFilter());
    }

    public function testChainedFilterFeedback() {
        $filter1 = FilterBuilder::begin()
            ->setHandle("Filter1")
            ->setType(Filter::TYPE_STATIC)
            ->setFieldName((string)rand())
            ->setCondition((string)rand())
            ->setCriterion((string)rand())
            ->build();

        $filter2 = FilterBuilder::begin()
            ->setHandle("Filter2")
            ->setNextFilter($filter1)
            ->setType(Filter::TYPE_PAGINATION)
            ->build();

        $this->assertEquals(2, sizeof($filter2->getFeedback()));
    }

    public function testChainedFilterByQuery() {
        $filter1 = FilterBuilder::begin()
            ->setHandle("Filter1")
            ->setType(Filter::TYPE_STATIC)
            ->setFieldName("TestClass.Id")
            ->setCondition(FilterStatement::COND_SORT_ASC)
            ->build();

        $filter2 = FilterBuilder::begin()
            ->setNextFilter($filter1)
            ->setHandle("Filter2")
            ->setType(Filter::TYPE_STATIC)
            ->setFieldName("TestClass.FieldFloat")
            ->setCondition(FilterStatement::COND_SORT_DESC)
            ->build();

        // use MockQuery from FilterStatementTest
        $query = new MockQuery();
        $query = $filter2->queryFilter($query);

        $this->assertContains(["TestClass.Id", "ASC"], $query->orderByStatements);
        $this->assertContains(["TestClass.FieldFloat", "DESC"], $query->orderByStatements);
    }

    public function testForceFilterByRow() {
        $filter1 = FilterBuilder::begin()
            ->setHandle("Filter1")
            ->setType(Filter::TYPE_STATIC)
            ->setFieldName("TestClass.Id")
            ->setCondition(FilterStatement::COND_SORT_ASC)
            ->build();

        // This filter will force a row sort because the field name is not
        // available to the query.
        $filter2 = FilterBuilder::begin()
            ->setNextFilter($filter1)
            ->setHandle("Filter2")
            ->setType(Filter::TYPE_STATIC)
            ->setFieldName("TestClass.MadeUpFieldToForceRowSort")
            ->setCondition(FilterStatement::COND_SORT_DESC)
            ->build();

        // This filter could be done by query, except that the previous
        // filter has forced the whole chain into row-filtering.
        $filter3 = FilterBuilder::begin()
            ->setNextFilter($filter2)
            ->setHandle("Filter3")
            ->setType(Filter::TYPE_STATIC)
            ->setFieldName("TestClass.FieldFloat")
            ->setCondition(FilterStatement::COND_SORT_DESC)
            ->build();

        // use MockQuery from FilterStatementTest
        $query = new MockQuery();
        $query = $filter3->queryFilter($query);

        $this->assertContains(["TestClass.Id", "ASC"], $query->orderByStatements);
        $this->assertEquals(1, sizeof($query->orderByStatements));
    }

}

