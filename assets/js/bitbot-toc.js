/**
 * BitBot TOC rail — plugin-owned guarantee for the `bb-toc-rail` container.
 *
 * The agent's template markup ships an EMPTY <div class="bb-toc-rail"> and
 * promises "a table of contents that highlights the section you're reading".
 * This file is what makes that promise true on any theme, for any post:
 * it builds the list from the post's real headings at render time (so it can
 * never drift from the content) and scroll-spies with IntersectionObserver.
 *
 * No dependencies, no config. If the rail or the headings are missing, it
 * removes the rail's column space cleanly and does nothing.
 */
(function () {
  'use strict';

  function slugify(text, taken) {
    var base = (text || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'section';
    var slug = base;
    var n = 2;
    while (taken[slug]) { slug = base + '-' + n++; }
    taken[slug] = true;
    return slug;
  }

  function init() {
    var rail = document.querySelector('.bb-toc-rail');
    if (!rail) return;
    var content = document.querySelector('.wp-block-post-content');
    // NEVER two tables of contents. Some posts are built with a design kit
    // that bakes its OWN in-content TOC; the template rail would then render a
    // second one beside it (observed live on three posts). The in-content one
    // is part of the article, so the RAIL yields to it.
    if (content && (
      content.querySelector('.bb-toc, .bb-post-toc, .bb-toc-rail, [class*="table-of-contents"]') ||
      Array.prototype.some.call(
        content.querySelectorAll('h2, h3, h4, p > strong, .wp-block-heading'),
        function (el) { return /^\s*(on this page|table of contents|in this (article|guide))\s*$/i.test(el.textContent || ''); }
      )
    )) {
      rail.classList.add('bb-toc-empty');
      return;
    }
    var headings = content ? Array.prototype.slice.call(content.querySelectorAll('h2')) : [];
    // A one-heading post gets no rail — a TOC with one entry is furniture.
    if (headings.length < 2) {
      rail.classList.add('bb-toc-empty');
      return;
    }

    var taken = {};
    // Respect existing ids (in-content anchor links keep working); mint the rest.
    headings.forEach(function (h) {
      if (h.id) { taken[h.id] = true; }
    });
    var list = document.createElement('nav');
    list.className = 'bb-toc-nav';
    list.setAttribute('aria-label', 'Table of contents');
    var title = document.createElement('div');
    title.className = 'bb-toc-title';
    title.textContent = 'On this page';
    list.appendChild(title);
    var ul = document.createElement('ul');
    var links = new Map();
    headings.forEach(function (h) {
      if (!h.id) h.id = slugify(h.textContent, taken);
      var li = document.createElement('li');
      li.className = 'bb-toc-top';
      var a = document.createElement('a');
      a.href = '#' + h.id;
      a.textContent = h.textContent;
      li.appendChild(a);
      ul.appendChild(li);
      links.set(h.id, a);
    });
    list.appendChild(ul);
    rail.appendChild(list);

    // Scroll-spy: the topmost heading at or above the reading line wins.
    // IntersectionObserver alone flickers between sections on tall content,
    // so observe transitions but resolve the ACTIVE one from scroll position.
    var active = null;
    function setActive(id) {
      if (active === id) return;
      if (active && links.get(active)) links.get(active).classList.remove('bb-toc-active');
      if (id && links.get(id)) links.get(id).classList.add('bb-toc-active');
      active = id;
    }
    function resolve() {
      var line = window.innerHeight * 0.25;
      var current = headings[0].id;
      for (var i = 0; i < headings.length; i++) {
        if (headings[i].getBoundingClientRect().top <= line) current = headings[i].id;
        else break;
      }
      setActive(current);
    }
    var observer = new IntersectionObserver(resolve, { rootMargin: '-20% 0px -60% 0px' });
    headings.forEach(function (h) { observer.observe(h); });
    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () { resolve(); ticking = false; });
    }, { passive: true });
    resolve();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
