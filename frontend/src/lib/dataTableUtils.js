export function parseColumnType(type) {
    if (!type) return { base: "", length: "" };
    const m = type.match(/^(.+?)\((.+)\)$/);
    return m
        ? { base: m[1].trim(), length: m[2].trim() }
        : { base: type, length: "" };
}

export function buildType(base, length) {
    const b = base.trim();
    const l = length.trim();
    return b && l ? `${b}(${l})` : b;
}

export function supportsLength(typeBase, caps) {
    return (caps?.columnTypesWithLength ?? []).some(
        (t) => t.toUpperCase() === typeBase.toUpperCase(),
    );
}

export function formatCell(v) {
    if (v === null || v === undefined) return null;
    if (typeof v === "object") return JSON.stringify(v);
    return String(v);
}

export function isAutoIncrement(c) {
    return c?.autoIncrement === true;
}

export function isPrimary(c) {
    return c && c.key === "primary";
}

export function isInteractiveTarget(e) {
    return !!e.target.closest("input, button, select, label");
}
