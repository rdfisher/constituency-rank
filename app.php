<?php
require_once ('vendor/autoload.php');
use Psr\Http\Message\ResponseInterface;
use Clue\React\Buzz\Browser;

$loop = React\EventLoop\Factory::create();
$browser = new Browser($loop);
$petitionRetriever = new PetitionRetriever($browser);
$comparator = new Comparator($petitionRetriever);
$renderer = new Renderer($comparator);

class Petition
{
    /** @var int */
    private $id;

    /** @var string */
    private $title;

    /** @var ConstituencyResult[] */
    private $results = [];

    /** @var int */
    private $total = 0;

    public function __construct(int $id, string $title)
    {
        $this->id = $id;
        $this->title = $title;
    }

    public function getId() : int
    {
        return $this->id;
    }

    public function getTitle() : string
    {
        return $this->title;
    }

    public function addConstituencyResult(ConstituencyResult $result)
    {
        if (isset($this->results[$result->getName()])) {
            return;
        }

        $this->results[$result->getName()] = $result;
        $this->total += $result->getCount();
    }

    public function getTotal() : int
    {
        return $this->total;
    }

    public function getConstituencyResult(string $name) : ConstituencyResult
    {
        if (isset($this->results[$name])) {
            return $this->results[$name];
        }

        return new ConstituencyResult($name, 0);
    }

    public function getResults() : array
    {
        return $this->results;
    }
}

class ConstituencyResult
{
    /** @var string */
    private $name;

    /** @var int */
    private $count;

    public function __construct(string $name, int $count)
    {
        $this->name = $name;
        $this->count = $count;
    }

    public function getName() : string
    {
        return $this->name;
    }

    public function getCount() : int
    {
        return $this->count;
    }
}

class ConstituencyComparison
{
    /** @var string */
    private $name;

    /** @var float */
    private $bias;

    private $hasValidData = true;

    public function __construct(Petition $petitionA, Petition $petitionB, string $name)
    {
        $this->name = $name;
        $petitionRatio = $petitionA->getTotal() / $petitionB->getTotal();
        $resultA = $petitionA->getConstituencyResult($name);
        $resultB = $petitionB->getConstituencyResult($name);

        if (! ($resultA->getCount() && $resultB->getCount())) {
            $this->hasValidData = false;
            return;
        }

        $resultRatio = $resultA->getCount() / $resultB->getCount();

        $this->bias = $resultRatio / $petitionRatio;
    }

    public function getBias() : float
    {
        if (! $this->hasValidData) {
            throw new LogicException('Results cannot be compared, insufficient data');
        }
        return $this->bias;
    }

    public function isValid() : bool
    {
        return $this->hasValidData;
    }

    public function getName() : string
    {
        return $this->name;
    }
}

class Comparator
{
    /**
     * @var PetitionRetriever
     */
    private $retriever;

    public function __construct(PetitionRetriever $retriever)
    {
        $this->retriever = $retriever;
    }

    public function getComparison(int $petitionId1, int $petitionId2)
    {
        return \React\Promise\all([$this->retriever->getPetitionById($petitionId1), $this->retriever->getPetitionById($petitionId2)])
            ->then(function ($petitions) {
                list($petition1, $petition2)  = $petitions;
                $comparison = new PetitionComparison($petition1, $petition2);
                foreach ($petition1->getResults() as $result) {
                    $comparison->addConstituencyComparison(new ConstituencyComparison($petition1, $petition2, $result->getName()));
                }
                return $comparison;
            });
    }
}

class PetitionComparison
{
    /** @var Petition */
    private $petition1;

    /** @var Petition */
    private $petition2;

    private $constituencyComparisons = [];

    public function __construct(Petition $petition1, Petition $petition2)
    {
        $this->petition1 = $petition1;
        $this->petition2 = $petition2;
    }

    public function addConstituencyComparison(ConstituencyComparison $constituencyComparison)
    {
        if ($constituencyComparison->isValid()) {
            $this->constituencyComparisons[] = $constituencyComparison;
        }
    }

    public function getConstituencyComparisons()
    {
        usort($this->constituencyComparisons, function(ConstituencyComparison $a, ConstituencyComparison $b) {
            return $a->getBias() < $b->getBias();
        });
        return $this->constituencyComparisons;
    }

    public function getPetition1() : Petition
    {
        return $this->petition1;
    }

    public function getPetition2() : Petition
    {
        return $this->petition2;
    }
}

class PetitionRetriever
{
    /** @var Browser */
    private $browser;

    public function __construct(Browser $browser)
    {
        $this->browser = $browser;
    }

    public function getPetitionById(int $id)
    {
        return $this->browser->get('https://petition.parliament.uk/petitions/' . $id . '.json')
            ->then(function(ResponseInterface $response) {
                $data = json_decode($response->getBody(), true);
                $petition = new Petition($data['data']['id'], $data['data']['attributes']['action']);
                foreach ($data['data']['attributes']['signatures_by_constituency'] as $constituencyData) {
                    $constituencyResult = new ConstituencyResult($constituencyData['name'], $constituencyData['signature_count']);
                    $petition->addConstituencyResult($constituencyResult);
                }
                return $petition;
            });
    }
}

class Renderer
{
    /** @var Comparator */
    private $comparator;

    public function __construct(Comparator $comparator)
    {
        $this->comparator = $comparator;
    }

    public function renderComparison(int $petitionId1, int $petitionId2)
    {
        $this->comparator->getComparison($petitionId1, $petitionId2)
            ->then(function(PetitionComparison $comparison) {
                echo 'A: "' . $comparison->getPetition1()->getTitle() . '"' . "\n\tvs\n" . 'B: "' . $comparison->getPetition2()->getTitle() . '"' . "\n";
                echo "\nTop 20 most biased towards option A:\n";
                foreach (array_slice($comparison->getConstituencyComparisons(), 0, 20) as $constituency) {
                    echo "\t- " . $constituency->getName() . "\n";
                }
                echo "\nTop 20 most biased towards option B:\n";
                foreach (array_slice($comparison->getConstituencyComparisons(), -20, 20) as $constituency) {
                    echo "\t- " . $constituency->getName() . "\n";
                }
            }, function($e) { var_dump($e->getMessage());});
    }
}

$renderer->renderComparison(241584, 229963);
$loop->run();