/** Drupal globals supplied by the core/drupal and core/once libraries. */
interface NovaPayDrupalBehavior {
  attach(context: Document | Element): void;
}

declare const Drupal: {
  behaviors: Record<string, NovaPayDrupalBehavior>;
};

declare function once(
  id: string,
  selector: string,
  context: Document | Element,
): Element[];
