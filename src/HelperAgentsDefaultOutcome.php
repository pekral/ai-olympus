<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

enum HelperAgentsDefaultOutcome
{

    case Written;
    case Unchanged;
    case FormatKept;
    case WriteFailed;

}
