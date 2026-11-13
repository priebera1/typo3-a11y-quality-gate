/**
 * Exact targeting in plain HTML source for AQG editor AI fixes.
 *
 * The live check returns, per AI-eligible finding, the element it is about: tag name, its index among all elements
 * with that tag in document order (occurrence), the total count, and the element's attributes and text. This module
 * tokenizes the current source with character offsets and proves it found the same element: same total, the
 * occurrence-th start tag, identical attributes (and text where the element has no child elements). It never searches
 * for a string, so identical duplicates stay distinct.
 *
 * Changes are range-bound (Atlas range contract): set one attribute value on the target start tag, or replace the text
 * of a target element without child elements. Every change is dry-run and checked before it can be applied.
 */

const VOID_ELEMENTS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const RAW_TEXT_ELEMENTS = new Set(['script', 'style', 'textarea', 'title', 'xmp']);

export const normalizeText = (value) => String(value ?? '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();

let decoder = null;
export const decodeEntities = (value) => {
    const text = String(value ?? '');
    if (!text.includes('&')) {
        return text;
    }
    decoder = decoder ?? document.createElement('textarea');
    decoder.innerHTML = text;
    return decoder.value;
};

export const escapeAttributeValue = (value, quote = '"') => {
    let escaped = String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    escaped = quote === "'" ? escaped.replace(/'/g, '&#39;') : escaped.replace(/"/g, '&quot;');
    return escaped;
};

export const escapeText = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * Start tags of the source with offsets. Comments, doctype/processing instructions and raw-text element content are
 * skipped like an HTML parser does. Unterminated comments, tags or quoted attribute values make the source
 * unparseable: AQG then cannot prove where a change would land.
 *
 * @returns {{ok: boolean, error?: string, at?: number, elements: Array}}
 */
export function tokenizeHtml(source) {
    const html = String(source ?? '');
    const elements = [];
    const length = html.length;
    let index = 0;

    while (index < length) {
        const lt = html.indexOf('<', index);
        if (lt === -1) {
            break;
        }
        if (html.startsWith('<!--', lt)) {
            const end = html.indexOf('-->', lt + 4);
            if (end === -1) {
                return { ok: false, error: 'unterminated_comment', at: lt, elements };
            }
            index = end + 3;
            continue;
        }
        const next = html[lt + 1] ?? '';
        if (next === '!' || next === '?') {
            const end = html.indexOf('>', lt + 2);
            if (end === -1) {
                return { ok: false, error: 'unterminated_tag', at: lt, elements };
            }
            index = end + 1;
            continue;
        }
        if (next === '/') {
            const match = /^<\/([a-zA-Z][^\s/>]*)\s*>/.exec(html.slice(lt, lt + 200));
            index = match ? lt + match[0].length : lt + 2;
            continue;
        }
        if (!/[a-zA-Z]/.test(next)) {
            index = lt + 1;
            continue;
        }

        const element = parseStartTag(html, lt);
        if (element.error) {
            return { ok: false, error: element.error, at: lt, elements };
        }
        elements.push(element);
        index = element.end;

        if (RAW_TEXT_ELEMENTS.has(element.tag) && !element.selfClosing) {
            const close = new RegExp(`</${element.tag}[\\s/>]`, 'ig');
            close.lastIndex = element.end;
            const match = close.exec(html);
            if (!match) {
                return { ok: false, error: 'unterminated_raw_text', at: lt, elements };
            }
            index = match.index;
        }
    }

    return { ok: true, elements };
}

function parseStartTag(html, start) {
    const nameMatch = /^<([a-zA-Z][^\s/>]*)/.exec(html.slice(start, start + 200));
    const tag = nameMatch[1].toLowerCase();
    const nameEnd = start + nameMatch[0].length;
    const attributes = [];
    let index = nameEnd;
    let selfClosing = false;

    while (index < html.length) {
        while (index < html.length && /\s/.test(html[index])) {
            index++;
        }
        if (index >= html.length) {
            break;
        }
        if (html[index] === '>') {
            return { tag, start, nameEnd, end: index + 1, selfClosing, attributes };
        }
        if (html[index] === '/') {
            if (html[index + 1] === '>') {
                selfClosing = true;
                return { tag, start, nameEnd, end: index + 2, selfClosing, attributes };
            }
            index++;
            continue;
        }

        const attributeStart = index;
        while (index < html.length && !/[\s/>=]/.test(html[index])) {
            index++;
        }
        if (index === attributeStart) {
            // A stray "=": an HTML parser starts the attribute name with it.
            index++;
        }
        const name = html.slice(attributeStart, index).toLowerCase();
        let lookahead = index;
        while (lookahead < html.length && /\s/.test(html[lookahead])) {
            lookahead++;
        }
        if (html[lookahead] !== '=') {
            attributes.push({ name, start: attributeStart, end: index, valueStart: -1, valueEnd: -1, quote: '', rawValue: '' });
            continue;
        }
        index = lookahead + 1;
        while (index < html.length && /\s/.test(html[index])) {
            index++;
        }
        const quote = html[index] === '"' || html[index] === "'" ? html[index] : '';
        if (quote !== '') {
            const close = html.indexOf(quote, index + 1);
            if (close === -1) {
                return { error: 'unterminated_attribute' };
            }
            attributes.push({ name, start: attributeStart, end: close + 1, valueStart: index + 1, valueEnd: close, quote, rawValue: html.slice(index + 1, close) });
            index = close + 1;
            continue;
        }
        const valueStart = index;
        while (index < html.length && !/[\s>]/.test(html[index])) {
            index++;
        }
        attributes.push({ name, start: attributeStart, end: index, valueStart, valueEnd: index, quote: '', rawValue: html.slice(valueStart, index) });
    }

    return { error: 'unterminated_tag' };
}

/** Attributes as an HTML parser sees them: lowercase names, decoded values, the first of duplicate names wins. */
export function attributeMap(element) {
    const map = {};
    for (const attribute of element.attributes) {
        if (!Object.prototype.hasOwnProperty.call(map, attribute.name)) {
            map[attribute.name] = attribute.valueStart === -1 ? '' : decodeEntities(attribute.rawValue);
        }
    }
    return map;
}

export const sameAttributes = (left, right) => {
    const leftKeys = Object.keys(left ?? {}).sort();
    const rightKeys = Object.keys(right ?? {}).sort();
    return leftKeys.length === rightKeys.length
        && leftKeys.every((key, index) => key === rightKeys[index] && String(left[key]) === String(right[key]));
};

/** The text of an element whose content has no markup: the range up to its own end tag, or null. */
export function plainContentRange(source, element) {
    if (VOID_ELEMENTS.has(element.tag) || element.selfClosing) {
        return null;
    }
    const html = String(source);
    const next = html.indexOf('<', element.end);
    if (next === -1) {
        return null;
    }
    const close = new RegExp(`^</${element.tag}\\s*>`, 'i').exec(html.slice(next, next + element.tag.length + 20));
    if (!close) {
        return null;
    }
    return { from: element.end, to: next, closeEnd: next + close[0].length, text: decodeEntities(html.slice(element.end, next)) };
}

export const lineAndColumn = (source, offset) => {
    const before = String(source).slice(0, Math.max(0, offset));
    const lines = before.split('\n');
    return { line: lines.length, column: lines[lines.length - 1].length + 1 };
};

/**
 * Finds the finding's element in the current source and proves it is the same one.
 *
 * @returns {{ok: boolean, reason?: string, element?: object, content?: object|null, line?: number, column?: number}}
 */
export function locateTarget(source, target) {
    if (!target || typeof target !== 'object' || !target.tag || !Number.isInteger(target.occurrence)) {
        return { ok: false, reason: 'no_target' };
    }
    const parsed = tokenizeHtml(source);
    if (!parsed.ok) {
        return { ok: false, reason: 'source_unparseable', error: parsed.error, at: parsed.at };
    }
    const candidates = parsed.elements.filter((element) => element.tag === target.tag);
    if (candidates.length !== target.total) {
        return { ok: false, reason: 'count_mismatch' };
    }
    const element = candidates[target.occurrence];
    if (!element) {
        return { ok: false, reason: 'count_mismatch' };
    }
    return proveElement(source, element, target);
}

/** Proves that the element starting at `offset` (a position tracked through edits) is still the target. */
export function locateTargetAt(source, offset, target) {
    const parsed = tokenizeHtml(source);
    if (!parsed.ok) {
        return { ok: false, reason: 'source_unparseable', error: parsed.error, at: parsed.at };
    }
    const element = parsed.elements.find((candidate) => candidate.start === offset && candidate.tag === target.tag);
    return element ? proveElement(source, element, target) : { ok: false, reason: 'stale' };
}

function proveElement(source, element, target) {
    const expected = target.element ?? {};
    if (!sameAttributes(attributeMap(element), expected.attributes ?? {})) {
        return { ok: false, reason: 'signature_mismatch' };
    }
    const content = plainContentRange(source, element);
    if (!expected.hasChildElements && content !== null && normalizeText(content.text) !== normalizeText(expected.text)) {
        return { ok: false, reason: 'signature_mismatch' };
    }
    if (target.operation === 'text' && content === null) {
        return { ok: false, reason: 'nested_markup' };
    }
    if (target.operation !== 'text' && target.operation !== 'attribute') {
        return { ok: false, reason: 'unsupported_operation' };
    }
    if (/\{[^}]*\}|<f:/i.test(String(source).slice(element.start, content ? content.closeEnd : element.end))) {
        return { ok: false, reason: 'template_placeholder' };
    }
    const position = lineAndColumn(source, element.start);
    return { ok: true, element, content, line: position.line, column: position.column };
}

/** The one range change that sets the value; nothing outside [from, to) changes. */
export function buildChange(source, located, target, value) {
    const element = located.element;
    if (target.operation === 'text') {
        return { from: located.content.from, to: located.content.to, insert: escapeText(value) };
    }
    const attribute = element.attributes.find((candidate) => candidate.name === target.attribute);
    if (attribute) {
        if (attribute.valueStart === -1) {
            return { from: attribute.end, to: attribute.end, insert: `="${escapeAttributeValue(value)}"` };
        }
        if (attribute.quote !== '') {
            return { from: attribute.valueStart, to: attribute.valueEnd, insert: escapeAttributeValue(value, attribute.quote) };
        }
        return { from: attribute.valueStart, to: attribute.valueEnd, insert: `"${escapeAttributeValue(value)}"` };
    }
    const insertAt = element.attributes.length > 0 ? element.attributes[element.attributes.length - 1].end : element.nameEnd;
    return { from: insertAt, to: insertAt, insert: ` ${target.attribute}="${escapeAttributeValue(value)}"` };
}

/**
 * Dry run of a change: the result must parse, keep every element and its order, keep text outside the range
 * byte-identical, and change only the allowed attribute or text of the target.
 *
 * @returns {{ok: boolean, checks: Array<{id: string, passed: boolean}>, result: string}}
 */
export function verifyChange(source, change, located, target, value) {
    const before = String(source);
    const result = before.slice(0, change.from) + change.insert + before.slice(change.to);
    const checks = [];
    const add = (id, passed) => {
        checks.push({ id, passed: Boolean(passed) });
        return Boolean(passed);
    };

    const startOfTarget = located.element.start;
    const endOfTarget = located.content ? located.content.closeEnd : located.element.end;
    add('range', change.from >= startOfTarget && change.to <= endOfTarget
        && result.slice(0, change.from) === before.slice(0, change.from)
        && result.slice(change.from + change.insert.length) === before.slice(change.to));

    const parsedBefore = tokenizeHtml(before);
    const parsedAfter = tokenizeHtml(result);
    const sameStructure = parsedBefore.ok && parsedAfter.ok
        && parsedBefore.elements.length === parsedAfter.elements.length
        && parsedBefore.elements.every((element, index) => element.tag === parsedAfter.elements[index].tag);
    add('structure', sameStructure);

    let changedAsAllowed = false;
    if (sameStructure && parsedBefore.elements.some((element) => element.start === located.element.start)) {
        const index = parsedBefore.elements.findIndex((element) => element.start === located.element.start);
        const after = parsedAfter.elements[index];
        const expectedAttributes = { ...attributeMap(located.element) };
        if (target.operation === 'attribute') {
            expectedAttributes[target.attribute] = value;
            changedAsAllowed = sameAttributes(attributeMap(after), expectedAttributes);
        } else {
            const content = plainContentRange(result, after);
            changedAsAllowed = sameAttributes(attributeMap(after), expectedAttributes)
                && content !== null
                && normalizeText(content.text) === normalizeText(value);
        }
        // Every other element keeps its attributes.
        changedAsAllowed = changedAsAllowed && parsedBefore.elements.every((element, position) => position === index
            || sameAttributes(attributeMap(element), attributeMap(parsedAfter.elements[position])));
    }
    add('target', changedAsAllowed);
    add('escaped', !/[<>]/.test(change.insert));

    return { ok: checks.every((check) => check.passed), checks, result };
}

/**
 * Before/after of the changed element for the source diff: the element's markup split into the unchanged head, the
 * removed or inserted part and the unchanged tail, with long parts elided ("…") around the change.
 */
export function sourceDiff(source, change, located, context = 60) {
    const before = String(source);
    const start = located.element.start;
    const end = located.content ? located.content.closeEnd : located.element.end;
    const clip = (text, keepEnd) => {
        if (text.length <= context) {
            return text;
        }
        return keepEnd ? `…${text.slice(text.length - context)}` : `${text.slice(0, context)}…`;
    };
    const head = clip(before.slice(start, change.from), true);
    const tail = clip(before.slice(change.to, end), false);

    return {
        line: lineAndColumn(before, start).line,
        head,
        removed: before.slice(change.from, change.to),
        inserted: change.insert,
        tail,
    };
}
