<?php

declare(strict_types=1);

namespace Pdp;

use function array_reverse;
use function array_slice;
use function count;
use function explode;
use function implode;
use function strlen;
use function substr;

final class ResolvedDomain implements ResolvedDomainName
{
    private DomainName $domain;

    private EffectiveTLD $publicSuffix;

    private DomainName $registrableDomain;

    private DomainName $subDomain;

    public function __construct(Host $domain, EffectiveTLD $publicSuffix = null)
    {
        $this->domain = $this->setDomainName($domain);
        $this->publicSuffix = $this->setPublicSuffix($publicSuffix);
        $this->registrableDomain = $this->setRegistrableDomain();
        $this->subDomain = $this->setSubDomain();
    }

    public static function __set_state(array $properties): self
    {
        return new self($properties['domain'], $properties['publicSuffix']);
    }

    private function setDomainName(Host $domain): DomainName
    {
        if ($domain instanceof ExternalDomainName) {
            return $domain->domain();
        }

        if ($domain instanceof DomainName) {
            return $domain;
        }

        return Domain::fromIDNA2008($domain->value());
    }

    /**
     * Sets the public suffix domain part.
     *
     * @throws UnableToResolveDomain If the public suffic can not be attached to the domain
     */
    private function setPublicSuffix(EffectiveTLD $publicSuffix = null): EffectiveTLD
    {
        if (null === $publicSuffix || null === $publicSuffix->value()) {
            $domain = $this->domain->isIdna2008() ? Domain::fromIDNA2008(null) : Domain::fromIDNA2003(null);

            return PublicSuffix::fromUnknown($domain);
        }

        if (2 > count($this->domain)) {
            throw UnableToResolveDomain::dueToUnresolvableDomain($this->domain);
        }

        if ('.' === substr($this->domain->toString(), -1, 1)) {
            throw UnableToResolveDomain::dueToUnresolvableDomain($this->domain);
        }

        $publicSuffix = $this->normalize($publicSuffix);
        if ($this->domain->value() === $publicSuffix->value()) {
            throw UnableToResolveDomain::dueToIdenticalValue($this->domain);
        }

        $psContent = $publicSuffix->toString();
        if ('.'.$psContent !== substr($this->domain->toString(), - strlen($psContent) - 1)) {
            throw UnableToResolveDomain::dueToMismatchedPublicSuffix($this->domain, $publicSuffix);
        }

        return $publicSuffix;
    }

    /**
     * Normalize the domain name encoding content.
     */
    private function normalize(EffectiveTLD $subject): EffectiveTLD
    {
        if ($subject->domain()->isIdna2008() === $this->domain->isIdna2008()) {
            return $subject->domain()->isAscii() === $this->domain->isAscii() ? $subject : $subject->toUnicode();
        }

        $newDomain = Domain::fromIDNA2003($subject->toUnicode()->value());
        if ($this->domain->isAscii()) {
            $newDomain = $newDomain->toAscii();
        }

        if ($subject->isPrivate()) {
            return PublicSuffix::fromPrivate($newDomain);
        }

        if ($subject->isICANN()) {
            return  PublicSuffix::fromICANN($newDomain);
        }

        return PublicSuffix::fromUnknown($newDomain);
    }

    /**
     * Computes the registrable domain part.
     */
    private function setRegistrableDomain(): DomainName
    {
        if (null === $this->publicSuffix->value()) {
            return $this->domain->isIdna2008() ? Domain::fromIDNA2008(null) : Domain::fromIDNA2003(null);
        }

        $domain = implode('.', array_slice(
            explode('.', $this->domain->toString()),
            count($this->domain) - count($this->publicSuffix) - 1
        ));

        $registrableDomain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($domain) : Domain::fromIDNA2003($domain);

        return $this->domain->isAscii() ? $registrableDomain->toAscii() : $registrableDomain->toUnicode();
    }

    /**
     * Computes the sub domain part.
     */
    private function setSubDomain(): DomainName
    {
        if (null === $this->registrableDomain->value()) {
            return $this->domain->isIdna2008() ? Domain::fromIDNA2008(null) : Domain::fromIDNA2003(null);
        }

        $nbLabels = count($this->domain);
        $nbRegistrableLabels = count($this->publicSuffix) + 1;
        if ($nbLabels === $nbRegistrableLabels) {
            return $this->domain->isIdna2008() ? Domain::fromIDNA2008(null) : Domain::fromIDNA2003(null);
        }

        $domain = implode('.', array_slice(
            explode('.', $this->domain->toString()),
            0,
            $nbLabels - $nbRegistrableLabels
        ));

        $subDomain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($domain) : Domain::fromIDNA2003($domain);

        return $this->domain->isAscii() ? $subDomain->toAscii() : $subDomain->toUnicode();
    }

    public function count(): int
    {
        return count($this->domain);
    }

    public function domain(): DomainName
    {
        return $this->domain;
    }

    public function jsonSerialize(): ?string
    {
        return $this->domain->value();
    }

    public function value(): ?string
    {
        return $this->domain->value();
    }

    public function toString(): string
    {
        return $this->domain->toString();
    }

    public function registrableDomain(): ResolvedDomain
    {
        return new self($this->registrableDomain, $this->publicSuffix);
    }

    public function secondLevelDomain(): ?string
    {
        return $this->registrableDomain->label(-1);
    }

    public function subDomain(): DomainName
    {
        return $this->subDomain;
    }

    public function publicSuffix(): EffectiveTLD
    {
        return $this->publicSuffix;
    }

    public function toAscii(): self
    {
        return new self($this->domain->toAscii(), $this->publicSuffix->toAscii());
    }

    public function toUnicode(): self
    {
        return new self($this->domain->toUnicode(), $this->publicSuffix->toUnicode());
    }

    /**
     * @param mixed $publicSuffix a public suffix
     */
    public function withPublicSuffix($publicSuffix): self
    {
        if (!$publicSuffix instanceof EffectiveTLD) {
            $publicSuffix = PublicSuffix::fromUnknown($publicSuffix);
        }

        $publicSuffix = $this->normalize($publicSuffix);
        if ($this->publicSuffix == $publicSuffix) {
            return $this;
        }

        $host = implode('.', array_reverse(array_slice($this->domain->labels(), count($this->publicSuffix))));

        if (null === $publicSuffix->value()) {
            $domain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($host) : Domain::fromIDNA2003($host);

            return new self($domain, null);
        }

        $host .= '.'.$publicSuffix->value();
        $domain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($host) : Domain::fromIDNA2003($host);

        return new self($domain, $publicSuffix);
    }

    /**
     * {@inheritDoc}
     */
    public function withSubDomain($subDomain): self
    {
        if (null === $this->registrableDomain->value()) {
            throw UnableToResolveDomain::dueToMissingRegistrableDomain($this->domain);
        }

        if ($subDomain instanceof ExternalDomainName) {
            $subDomain = $subDomain->domain();
        }

        if (!$subDomain instanceof DomainName) {
            $subDomain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($subDomain) : Domain::fromIDNA2003($subDomain);
        }

        $subDomain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($subDomain) : Domain::fromIDNA2003($subDomain);
        if ($this->subDomain == $subDomain) {
            return $this;
        }

        /** @var DomainName $subDomain */
        $subDomain = $subDomain->toAscii();
        if (!$this->domain->isAscii()) {
            /** @var DomainName $subDomain */
            $subDomain = $subDomain->toUnicode();
        }

        $newDomainValue = $subDomain->toString().'.'.$this->registrableDomain->toString();
        $newDomain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($newDomainValue) : Domain::fromIDNA2003($newDomainValue);

        return new self($newDomain, $this->publicSuffix);
    }

    public function withSecondLevelDomain($label): self
    {
        if (null === $this->registrableDomain->value()) {
            throw UnableToResolveDomain::dueToMissingRegistrableDomain($this->domain);
        }

        $newRegistrableDomain = $this->registrableDomain->withLabel(-1, $label);
        if ($newRegistrableDomain == $this->registrableDomain) {
            return $this;
        }

        if (null === $this->subDomain->value()) {
            return new self($newRegistrableDomain, $this->publicSuffix);
        }

        $newDomainValue = $this->subDomain->value().'.'.$newRegistrableDomain->value();
        $newDomain = $this->domain->isIdna2008() ? Domain::fromIDNA2008($newDomainValue) : Domain::fromIDNA2003($newDomainValue);

        return new self($newDomain, $this->publicSuffix);
    }
}