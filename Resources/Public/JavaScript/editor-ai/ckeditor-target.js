/**
 * Exact targeting in CKEditor 5 for AQG editor AI fixes.
 *
 * The live check describes the finding's element in editor.getData(): tag, occurrence among elements with that tag,
 * total count, attributes and text. This module finds the model item behind it and proves it through CKEditor's own
 * data pipeline: the model content of the candidate serializes to exactly that one element, and the content before it
 * serializes to exactly `occurrence` elements of that tag. A model marker then tracks the target through later edits
 * (markers are outside the undo stack and outside the data). Changes go through one editor.model.change() batch, so
 * Ctrl+Z restores the text; a change whose result does not serialize as expected is undone at once.
 */

const MARKER_PREFIX = 'aqgAiTarget:';
const IGNORED_EDITING_ANCESTORS = '.ck-widget__type-around, .ck-widget__resizer, .ck-fake-selection-container, [data-cke-ignore-events]';

export const normalizeText = (value) => String(value ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

const parseHtml = (html) => new DOMParser().parseFromString(`<!doctype html><html><body>${html}</body></html>`, 'text/html').body;

const domAttributes = (element) => {
    const map = {};
    for (const attribute of Array.from(element.attributes)) {
        map[attribute.name.toLowerCase()] = attribute.value;
    }
    return map;
};

const sameAttributes = (left, right) => {
    const leftKeys = Object.keys(left ?? {}).sort();
    const rightKeys = Object.keys(right ?? {}).sort();
    return leftKeys.length === rightKeys.length
        && leftKeys.every((key, index) => key === rightKeys[index] && String(left[key]) === String(right[key]));
};

/** The element signature as the server describes it, read from serialized editor data. */
export const signatureOf = (element) => ({
    tag: element.tagName.toLowerCase(),
    attributes: domAttributes(element),
    text: normalizeText(element.textContent),
    hasChildElements: element.children.length > 0,
});

export const matchesSignature = (signature, expected, {compareText = true} = {}) => signature.tag === expected.tag
    && sameAttributes(signature.attributes, expected.attributes)
    && (!compareText || normalizeText(signature.text) === normalizeText(expected.text));

/** Serializes model content through the editor's data pipeline (the same path as editor.getData()). */
const serializeRange = (editor, range) => {
    const model = editor.model;
    const fragment = model.getSelectedContent(model.createSelection(range));
    return editor.data.stringify(fragment);
};

const elementsIn = (html, tag) => Array.from(parseHtml(html).getElementsByTagName(tag));

const isStrictPathPrefix = (prefix, path) => prefix.length < path.length && prefix.every((value, index) => value === path[index]);

/**
 * How many `tag` elements the editor data has before a model position (document order, as editor.getData() writes it).
 *
 * CKEditor's getSelectedContent() throws "model-createpositionat-offset-required" for a range whose start path is a
 * strict prefix of its end path: [0] → [0, 61] is any position inside the first top-level block (release QA
 * 2026-11-12, TYPO3 13.4 / CKEditor 47.6: "Fix with AI" did nothing for a link in the first paragraph). There the
 * content after the position is counted instead; its range ends at the root, so it never has that shape.
 * That count puts an ancestor with the same tag (a table in a table) after the position, so the helper is only exact for
 * tags that never nest in themselves (a, img, button, iframe, h*: EditorAiRuleCatalog). An inconsistent count gives -1,
 * which matches no occurrence: the target is refused, never guessed.
 */
export function countElementsBefore(editor, position, tag) {
    const model = editor.model;
    const root = model.document.getRoot();
    const start = model.createPositionAt(root, 0);
    if (!isStrictPathPrefix(start.path, position.path)) {
        return elementsIn(serializeRange(editor, model.createRange(start, position)), tag).length;
    }
    const after = elementsIn(serializeRange(editor, model.createRange(position, model.createPositionAt(root, 'end'))), tag).length;
    const before = elementsIn(editor.getData(), tag).length - after;
    return before >= 0 ? before : -1;
}

/** The model item behind an editing-view DOM element: a text range for links, an element otherwise. */
function modelTargetFor(editor, domElement, tag) {
    const view = editor.editing.view;
    const mapper = editor.editing.mapper;
    const domConverter = view.domConverter;

    if (tag === 'a') {
        const viewElement = domConverter.mapDomToView(domElement);
        if (viewElement && viewElement.is && viewElement.is('attributeElement')) {
            const range = mapper.toModelRange(view.createRangeOn(viewElement));
            return range && !range.isCollapsed ? {type: 'range', range} : null;
        }
        return null;
    }

    let node = domElement;
    const root = view.getDomRoot();
    while (node && node !== root) {
        let viewElement = domConverter.mapDomToView(node);
        if (!viewElement && typeof domConverter.getHostViewElement === 'function') {
            viewElement = domConverter.getHostViewElement(node);
        }
        while (viewElement) {
            const modelElement = mapper.toModelElement(viewElement);
            if (modelElement) {
                return {type: 'element', element: modelElement};
            }
            viewElement = viewElement.parent;
        }
        node = node.parentNode;
    }
    return null;
}

const rangeOf = (editor, modelTarget) => (modelTarget.type === 'range'
    ? modelTarget.range
    : editor.model.createRangeOn(modelTarget.element));

/**
 * Proves a model candidate is the target: it serializes to exactly the target element, and exactly `occurrence`
 * elements of that tag come before it in the editor data.
 */
function proveCandidate(editor, modelTarget, target) {
    const range = rangeOf(editor, modelTarget);
    const own = elementsIn(serializeRange(editor, range), target.tag);
    if (own.length !== 1 || !matchesSignature(signatureOf(own[0]), target.element, {compareText: !target.element.hasChildElements})) {
        return false;
    }
    return countElementsBefore(editor, range.start, target.tag) === target.occurrence;
}

/** All text of a link range shares one attribute set (formatting) and contains no inline objects. */
function linkTextShape(range) {
    let attributes = null;
    for (const item of range.getItems({shallow: true})) {
        if (!item.is('$textProxy')) {
            return {ok: false, reason: 'nested_markup'};
        }
        const current = JSON.stringify(Array.from(item.getAttributes()).sort(([a], [b]) => a.localeCompare(b)));
        if (attributes !== null && attributes !== current) {
            return {ok: false, reason: 'mixed_formatting'};
        }
        attributes = current;
    }
    return {ok: true};
}

/**
 * Locates the finding's element in the editor and locks it with a model marker.
 *
 * @returns {{ok: boolean, reason?: string, markerName?: string, snapshot?: object}}
 */
export function locateCkeditorTarget(editor, target, lockId) {
    if (!target || !target.tag || !Number.isInteger(target.occurrence)) {
        return {ok: false, reason: 'no_target'};
    }
    const dataElements = elementsIn(editor.getData(), target.tag);
    if (dataElements.length !== target.total) {
        return {ok: false, reason: 'count_mismatch'};
    }
    const dataElement = dataElements[target.occurrence];
    if (!matchesSignature(signatureOf(dataElement), target.element, {compareText: !target.element.hasChildElements})) {
        return {ok: false, reason: 'signature_mismatch'};
    }

    const domRoot = editor.editing.view.getDomRoot();
    if (!domRoot) {
        return {ok: false, reason: 'no_editing_root'};
    }
    const candidates = Array.from(domRoot.querySelectorAll(target.tag))
        .filter((element) => !element.closest(IGNORED_EDITING_ANCESTORS));
    const ordered = candidates.length === target.total
        ? [candidates[target.occurrence], ...candidates.filter((_, index) => index !== target.occurrence)]
        : candidates;

    let proven = null;
    for (const candidate of ordered) {
        const modelTarget = candidate ? modelTargetFor(editor, candidate, target.tag) : null;
        if (modelTarget && proveCandidate(editor, modelTarget, target)) {
            proven = modelTarget;
            break;
        }
    }
    if (!proven) {
        return {ok: false, reason: 'not_provable'};
    }
    if (target.operation === 'text') {
        if (proven.type === 'element') {
            const shape = Array.from(proven.element.getChildren()).every((child) => child.is('$text'));
            if (!shape) {
                return {ok: false, reason: 'nested_markup'};
            }
        } else {
            const shape = linkTextShape(proven.range);
            if (!shape.ok) {
                return shape;
            }
        }
    }

    const markerName = `${MARKER_PREFIX}${lockId}`;
    const range = proven.type === 'range' ? linkRunAround(editor, proven.range) : rangeOf(editor, proven);
    editor.model.change((writer) => {
        if (editor.model.markers.has(markerName)) {
            writer.removeMarker(markerName);
        }
        writer.addMarker(markerName, {range, usingOperation: false, affectsData: false});
    });

    return {ok: true, markerName, snapshot: currentSignature(editor, markerName, target.tag)};
}

/**
 * The whole link a text range belongs to: adjacent text with the same linkHref. Typing at either end of a link
 * extends the link but not a model marker (marker gravity), so stale checks compare the whole link.
 */
export function linkRunAround(editor, range) {
    const href = (node) => (node && node.is && node.is('$text') ? node.getAttribute('linkHref') : undefined);
    const first = Array.from(range.getItems({shallow: true}))[0];
    const linkHref = first && first.getAttribute ? first.getAttribute('linkHref') : undefined;
    if (linkHref === undefined) {
        return range;
    }
    // Typing at a link's end merges into the same text node: a position inside a text node has no nodeAfter or
    // nodeBefore, only textNode. Both cases extend the run.
    const model = editor.model;
    let start = range.start;
    for (let guard = 0; guard < 1000; guard++) {
        if (start.textNode && href(start.textNode) === linkHref) {
            start = model.createPositionAt(start.parent, start.textNode.startOffset);
        } else if (start.nodeBefore && href(start.nodeBefore) === linkHref) {
            start = start.getShiftedBy(-start.nodeBefore.offsetSize);
        } else {
            break;
        }
    }
    let end = range.end;
    for (let guard = 0; guard < 1000; guard++) {
        if (end.textNode && href(end.textNode) === linkHref) {
            end = model.createPositionAt(end.parent, end.textNode.endOffset);
        } else if (end.nodeAfter && href(end.nodeAfter) === linkHref) {
            end = end.getShiftedBy(end.nodeAfter.offsetSize);
        } else {
            break;
        }
    }
    return model.createRange(start, end);
}

/** The serialized signature of what the marker covers now, or null when the target is gone or grew. */
export function currentSignature(editor, markerName, tag) {
    const marker = editor.model.markers.get(markerName);
    if (!marker) {
        return null;
    }
    const range = marker.getRange();
    if (range.isCollapsed) {
        return null;
    }
    if (tag === 'a' && !linkRunAround(editor, range).isEqual(range)) {
        return null;
    }
    const own = elementsIn(serializeRange(editor, range), tag);
    return own.length === 1 ? signatureOf(own[0]) : null;
}

/** Was the locked target edited since the suggestion was requested? */
export function isStale(editor, markerName, tag, snapshot) {
    const current = currentSignature(editor, markerName, tag);
    return current === null || snapshot === null || !matchesSignature(current, snapshot);
}

export function releaseTarget(editor, markerName) {
    if (!markerName || !editor.model.markers.has(markerName)) {
        return;
    }
    editor.model.change((writer) => writer.removeMarker(markerName));
}

/** The view name ("h3") of a heading model element, and the model name for a level (from heading.options). */
const headingModelName = (editor, level) => {
    const options = editor.config.get('heading.options') ?? [];
    const option = options.find((candidate) => {
        const view = typeof candidate.view === 'string' ? candidate.view : candidate.view?.name;
        return view === `h${level}` && (typeof candidate.view === 'string' || !candidate.view?.classes);
    });
    return option?.model ?? '';
};

export const canChangeHeadingLevel = (editor, level) => headingModelName(editor, level) !== '';

/** The level of the last heading before a position, from the editor's heading options (0 when none). */
function previousHeadingLevel(editor, position) {
    const options = editor.config.get('heading.options') ?? [];
    const levelOf = (name) => {
        const option = options.find((candidate) => candidate.model === name);
        const view = typeof option?.view === 'string' ? option.view : option?.view?.name;
        const match = /^h([1-6])$/.exec(view ?? '');
        return match ? Number(match[1]) : 0;
    };
    const root = editor.model.document.getRoot();
    let level = 0;
    for (const item of editor.model.createRange(editor.model.createPositionAt(root, 0), position).getItems()) {
        if (item.is('element') && levelOf(item.name) > 0) {
            level = levelOf(item.name);
        }
    }
    return level;
}

/**
 * CKEditor's undo command reverts the newest change when it is given a batch that is no longer on its stack (already
 * undone, or dropped): undo a batch only while it is still there.
 */
export function isOnUndoStack(editor, batch) {
    const stack = editor.commands.get('undo')?._stack;
    return Array.isArray(stack) && stack.some((entry) => entry.batch === batch);
}

/** GHS stores attributes of objects such as <iframe>, <button> or a GHS <img> in html<Tag>Attributes. */
const ghsAttributeKey = (tag) => `html${tag.charAt(0).toUpperCase()}${tag.slice(1)}Attributes`;

/**
 * Applies the reviewed value to the locked target in one undoable batch, then checks the serialized result. A result
 * that is not exactly the expected element is undone and reported as rejected.
 *
 * @returns {{ok: boolean, reason?: string, expected?: object}}
 */
export function applyCkeditorChange(editor, markerName, target, snapshot, value) {
    // CKEditor's read-only mode does not block model writers, and TYPO3 still submits a read-only field.
    if (editor.isReadOnly) {
        return {ok: false, reason: 'read_only'};
    }
    if (isStale(editor, markerName, target.tag, snapshot)) {
        return {ok: false, reason: 'stale'};
    }
    const model = editor.model;
    const range = model.markers.get(markerName).getRange();
    // Formatting added after the suggestion (bold inside the link, a line break in the heading) would be lost.
    if (target.operation === 'text') {
        const element = range.start.nodeAfter;
        const shape = target.tag === 'a'
            ? linkTextShape(range)
            : {ok: Boolean(element && element.is('element') && Array.from(element.getChildren()).every((child) => child.is('$text'))), reason: 'nested_markup'};
        if (!shape.ok) {
            return {ok: false, reason: shape.reason};
        }
    }
    // A rule-based level is only right while the heading before it keeps its level.
    if (target.operation === 'heading_level' && previousHeadingLevel(editor, range.start) + 1 !== target.targetHeadingLevel) {
        return {ok: false, reason: 'stale'};
    }
    const expected = {tag: snapshot.tag, attributes: {...snapshot.attributes}, text: snapshot.text};
    let batch = null;
    let failure = '';

    if (target.operation === 'heading_level') {
        const modelName = headingModelName(editor, target.targetHeadingLevel);
        if (modelName === '') {
            return {ok: false, reason: 'heading_level_unavailable'};
        }
        expected.tag = `h${target.targetHeadingLevel}`;
        model.change((writer) => {
            batch = writer.batch;
            const element = range.start.nodeAfter;
            if (!element || !element.is('element')) {
                failure = 'not_element';
                return;
            }
            writer.rename(element, modelName);
            writer.updateMarker(markerName, {range: writer.createRangeOn(element)});
        });
    } else if (target.operation === 'text') {
        expected.text = normalizeText(value);
        model.change((writer) => {
            batch = writer.batch;
            const element = range.start.nodeAfter;
            if (range.start.parent && element && element.is('element') && target.tag !== 'a') {
                // Heading text: replace the (empty or whitespace) content of the heading element.
                const inner = writer.createRangeIn(element);
                writer.remove(inner);
                writer.insertText(value, writer.createPositionAt(element, 0));
                writer.updateMarker(markerName, {range: writer.createRangeOn(element)});
                return;
            }
            const first = Array.from(range.getItems({shallow: true}))[0];
            const attributes = first ? Array.from(first.getAttributes()) : [];
            const start = range.start;
            writer.remove(range);
            writer.insertText(value, Object.fromEntries(attributes), start);
            writer.updateMarker(markerName, {range: writer.createRange(start, start.getShiftedBy(value.length))});
        });
    } else if (target.operation === 'attribute') {
        expected.attributes[target.attribute] = value;
        model.change((writer) => {
            batch = writer.batch;
            const element = range.start.nodeAfter;
            if (!element || !element.is('element')) {
                failure = 'not_element';
                return;
            }
            const schema = model.schema;
            if (target.attribute === 'alt' && ['imageInline', 'imageBlock'].includes(element.name) && schema.checkAttribute(element, 'alt')) {
                writer.setAttribute('alt', value, element);
                return;
            }
            const key = ghsAttributeKey(target.tag);
            if (!schema.checkAttribute(element, key)) {
                failure = 'unsupported_element';
                return;
            }
            const current = element.getAttribute(key);
            const next = current && typeof current === 'object' ? structuredClone(current) : {};
            next.attributes = {...(next.attributes ?? {}), [target.attribute]: value};
            writer.setAttribute(key, next, element);
        });
    } else {
        return {ok: false, reason: 'unsupported_operation'};
    }

    const result = failure === '' ? currentSignature(editor, markerName, expected.tag) : null;
    if (result === null || !matchesSignature(result, expected, {compareText: target.operation === 'text' || !target.element.hasChildElements})) {
        if (batch && batch.operations && batch.operations.length > 0 && isOnUndoStack(editor, batch)) {
            editor.execute('undo', batch);
            // The reverted change must not come back with Redo.
            editor.commands.get('redo')?.clearStack?.();
        }
        return {ok: false, reason: failure || 'apply_rejected'};
    }

    // Where the changed element stands now: Verify checks exactly this element in the saved record.
    const markerRange = model.markers.get(markerName).getRange();
    result.occurrence = countElementsBefore(editor, markerRange.start, expected.tag);
    result.total = elementsIn(editor.getData(), expected.tag).length;

    return {ok: true, expected: result, batch};
}
