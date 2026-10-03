import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../assets/src/controllers/workflow_controller.js', import.meta.url), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class', 'globalThis.WorkflowController = class');
const context = { DOMPoint: class {
    constructor(x, y) { this.x = x; this.y = y; }
    matrixTransform() { return this; }
} };
runInNewContext(source, context);
const create = () => {
    const controller = new context.WorkflowController();
    controller.initialViewBox = [0, 0, 100, 100];
    controller.viewBox = [0, 0, 100, 100];
    controller.svg = { setAttribute() {}, getScreenCTM: () => ({ inverse() {} }) };
    return controller;
};

test('zoom keeps the point under the pointer fixed and Fit restores the bounds', () => {
    const controller = create();
    controller.zoom(0.5, { x: 25, y: 75 });
    assert.deepEqual(Array.from(controller.viewBox), [12.5, 37.5, 50, 50]);
    controller.zoom(0.01);
    assert.equal(controller.viewBox[2], 50);
    controller.fit();
    assert.deepEqual(Array.from(controller.viewBox), [0, 0, 100, 100]);
});

test('ordinary scroll is untouched; Option scroll and pinch zoom', () => {
    for (const modifier of [null, 'altKey', 'ctrlKey']) {
        const controller = create();
        let prevented = false;
        controller.wheelZoom({ [modifier]: true, deltaY: -10, deltaMode: 0,
            clientX: 25, clientY: 75, preventDefault() { prevented = true; } });
        assert.equal(prevented, modifier !== null);
        assert.equal(controller.viewBox[2] < 100, modifier !== null);
    }
});
