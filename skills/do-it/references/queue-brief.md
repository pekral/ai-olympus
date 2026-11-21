# Queue brief

## Input of the helpers

`skills/_shared/order-work-queue.sh` reads one line per item, in the order of the instructions:

```text
<id>|<priority>|<difficulty>|<duration>|<method>|<depends-on>|<issue>|<evidence>
item-1|medium|STANDARD|7|files-and-criteria||new|search "csv export" found no open issue
item-2|medium|FAST|3|files-and-criteria|item-1|existing:41|instruction line 2 names #41
```

- `<priority>` is `critical`, `high`, `medium`, or `low`.
- `<difficulty>` is the `classify-risk.sh` tier: `FAST`, `STANDARD`, or `CRITICAL`.
- `<duration>` is the count of the method `files-and-criteria`, and `<method>` names it.
- `<issue>` is `new` or `existing:<number>`, and `<evidence>` states why. The helper refuses an empty evidence, a duration without a method, and an issue number that two items reuse.

The helper prints `order=`, `create=`, and `reuse=`. It creates nothing and reads nothing from the repository.

`commands/_shared/plan-parallel.sh` reads `<item id>|<tier>|<depends-on>|<files>` in the same order. Its limit is the manifest key `parallel.max-units`.
