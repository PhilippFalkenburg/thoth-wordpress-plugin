<?php

declare(strict_types=1);

namespace ThothWordPressPlugin\Metadata;

final class NestedMetadataMapper
{
    public function map(array $payload): array
    {
        $contributors = $this->mapContributors($payload);
        $affiliations = $this->mapAffiliations($payload);
        $subjects = $this->mapSubjects($payload);
        $fundings = $this->mapFundings($payload);
        $publications = $this->mapPublications($payload);
        $languages = $this->mapLanguages($payload);
        $issues = $this->mapIssues($payload);
        $series = $this->mapSeries($payload);
        $relatedWorks = $this->mapRelatedWorks($payload);

        return [
            'contributors' => $contributors,
            'affiliations' => $affiliations,
            'subjects' => $subjects,
            'fundings' => $fundings,
            'publications' => $publications,
            'languages' => $languages,
            'issues' => $issues,
            'series' => $series,
            'related_works' => $relatedWorks,
            'contributor_names' => $this->collectDistinctValues($contributors, 'fullName'),
            'institution_ids' => $this->collectDistinctValues($affiliations, 'institutionId'),
            'subject_codes' => $this->collectDistinctValues($subjects, 'subjectCode'),
            'funding_programs' => $this->collectDistinctValues($fundings, 'program'),
            'publication_isbns' => $this->collectDistinctValues($publications, 'isbn'),
            'language_codes' => $this->collectDistinctValues($languages, 'languageCode'),
            'issue_ids' => $this->collectDistinctValues($issues, 'issueId'),
            'series_ids' => $this->collectDistinctValues($series, 'seriesId'),
            'related_work_ids' => $this->collectDistinctValues($relatedWorks, 'relatedWorkId'),
        ];
    }

    private function mapContributors(array $payload): array
    {
        $contributions = $this->extractArray($payload, ['contributions']);
        $result = [];

        foreach ($contributions as $contribution) {
            if (!is_array($contribution)) {
                continue;
            }

            $contributor = isset($contribution['contributor']) && is_array($contribution['contributor'])
                ? $contribution['contributor']
                : [];

            $fullName = trim((string) ($contributor['fullName'] ?? ''));
            $result[] = [
                'contributionId' => (string) ($contribution['contributionId'] ?? ''),
                'contributionType' => (string) ($contribution['contributionType'] ?? ''),
                'mainContribution' => (bool) ($contribution['mainContribution'] ?? false),
                'contributorId' => (string) ($contributor['contributorId'] ?? ''),
                'fullName' => $fullName,
                'firstName' => (string) ($contributor['firstName'] ?? ''),
                'lastName' => (string) ($contributor['lastName'] ?? ''),
                'orcid' => (string) ($contributor['orcid'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapSubjects(array $payload): array
    {
        $subjects = $this->extractArray($payload, ['subjects']);
        $result = [];

        foreach ($subjects as $subject) {
            if (!is_array($subject)) {
                continue;
            }

            $result[] = [
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
                'subjectType' => (string) ($subject['subjectType'] ?? ''),
                'subjectCode' => (string) ($subject['subjectCode'] ?? ''),
                'subjectOrdinal' => (string) ($subject['subjectOrdinal'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapAffiliations(array $payload): array
    {
        $contributions = $this->extractArray($payload, ['contributions']);
        $result = [];

        foreach ($contributions as $contribution) {
            if (!is_array($contribution)) {
                continue;
            }

            $contributionId = (string) ($contribution['contributionId'] ?? '');
            $contributor = isset($contribution['contributor']) && is_array($contribution['contributor'])
                ? $contribution['contributor']
                : [];
            $contributorId = (string) ($contributor['contributorId'] ?? '');

            $affiliations = [];
            if (isset($contribution['affiliations']) && is_array($contribution['affiliations'])) {
                $affiliations = $contribution['affiliations'];
            } elseif (isset($contribution['institutionalAffiliations']) && is_array($contribution['institutionalAffiliations'])) {
                $affiliations = $contribution['institutionalAffiliations'];
            }

            foreach ($affiliations as $affiliation) {
                if (!is_array($affiliation)) {
                    continue;
                }

                $result[] = [
                    'contributionId' => $contributionId,
                    'contributorId' => $contributorId,
                    'institutionId' => (string) ($affiliation['institutionId'] ?? ''),
                    'institutionName' => (string) ($affiliation['institutionName'] ?? $affiliation['name'] ?? ''),
                    'position' => (string) ($affiliation['position'] ?? ''),
                    'affiliationOrdinal' => (string) ($affiliation['affiliationOrdinal'] ?? ''),
                ];
            }
        }

        return $this->compactEmptyRows($result);
    }

    private function mapFundings(array $payload): array
    {
        $fundings = $this->extractArray($payload, ['fundings']);
        $result = [];

        foreach ($fundings as $funding) {
            if (!is_array($funding)) {
                continue;
            }

            $result[] = [
                'fundingId' => (string) ($funding['fundingId'] ?? ''),
                'institutionId' => (string) ($funding['institutionId'] ?? ''),
                'projectName' => (string) ($funding['projectName'] ?? ''),
                'projectShortName' => (string) ($funding['projectShortName'] ?? ''),
                'grantNumber' => (string) ($funding['grantNumber'] ?? ''),
                'program' => (string) ($funding['program'] ?? ''),
                'jurisdiction' => (string) ($funding['jurisdiction'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapLanguages(array $payload): array
    {
        $languages = $this->extractArray($payload, ['languages']);
        $result = [];

        foreach ($languages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $result[] = [
                'languageCode' => strtolower((string) ($language['languageCode'] ?? '')),
                'languageRelation' => $this->normalizeLanguageRelation((string) ($language['languageRelation'] ?? '')),
                'mainLanguage' => (bool) ($language['mainLanguage'] ?? false),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapPublications(array $payload): array
    {
        $publications = $this->extractArray($payload, ['publications']);
        $result = [];

        foreach ($publications as $publication) {
            if (!is_array($publication)) {
                continue;
            }

            $result[] = [
                'publicationType' => $this->normalizePublicationType((string) ($publication['publicationType'] ?? '')),
                'isbn' => (string) ($publication['isbn'] ?? ''),
                'width' => (string) ($publication['width'] ?? ''),
                'height' => (string) ($publication['height'] ?? ''),
                'depth' => (string) ($publication['depth'] ?? ''),
                'weight' => (string) ($publication['weight'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapRelatedWorks(array $payload): array
    {
        $relatedWorks = $this->extractArray($payload, ['relatedWorks', 'workRelations']);
        $result = [];

        foreach ($relatedWorks as $relatedWork) {
            if (!is_array($relatedWork)) {
                continue;
            }

            $result[] = [
                'relatedWorkId' => (string) ($relatedWork['relatedWorkId'] ?? $relatedWork['workId'] ?? ''),
                'relationType' => $this->normalizeRelationType((string) ($relatedWork['relationType'] ?? '')),
                'relationOrdinal' => (string) ($relatedWork['relationOrdinal'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapIssues(array $payload): array
    {
        $issues = $this->extractArray($payload, ['issues']);
        $result = [];

        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $result[] = [
                'issueId' => (string) ($issue['issueId'] ?? ''),
                'issueNumber' => (string) ($issue['issueNumber'] ?? ''),
                'issueOrdinal' => (string) ($issue['issueOrdinal'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function mapSeries(array $payload): array
    {
        $series = $this->extractArray($payload, ['series', 'serieses']);
        $result = [];

        foreach ($series as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $result[] = [
                'seriesId' => (string) ($entry['seriesId'] ?? ''),
                'seriesName' => (string) ($entry['seriesName'] ?? $entry['name'] ?? ''),
                'seriesOrdinal' => (string) ($entry['seriesOrdinal'] ?? ''),
            ];
        }

        return $this->compactEmptyRows($result);
    }

    private function normalizeRelationType(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    private function normalizeLanguageRelation(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    private function normalizePublicationType(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    private function extractArray(array $payload, array $candidateKeys): array
    {
        foreach ($candidateKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    private function compactEmptyRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $hasContent = false;
            foreach ($row as $value) {
                if (is_bool($value)) {
                    if ($value) {
                        $hasContent = true;
                        break;
                    }
                    continue;
                }

                if (trim((string) $value) !== '') {
                    $hasContent = true;
                    break;
                }
            }

            if ($hasContent) {
                $result[] = $row;
            }
        }

        return $result;
    }

    private function collectDistinctValues(array $rows, string $field): string
    {
        $values = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = isset($row[$field]) ? trim((string) $row[$field]) : '';
            if ($value === '') {
                continue;
            }

            $values[$value] = true;
        }

        return implode(' | ', array_keys($values));
    }
}
