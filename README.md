DatabaseObject
==============

I wrote this ORM from scratch in 2004-2005, with a basic clear-up in
2009. My employer at the time was gracious enough for me to take the code
with me when I left the company, as the project it was intended for went
in another direction so it was no use to them.

It's thoroughly unsupported, and a lot of the code in it is archaic and/or
embarrassing(!)

However, I still use it on little one-off projects to do as a lone
developer... I haven't come across a PHP ORM quite as quick and
easy-to-use yet.

Features:

*  It's very grabby... in a good way. If you create tables well enough with
   the right indexes, you can hint to the model to go and get more data
   than necessary. You can also hint to the model NOT to do that too. This
   results in very large queries (pages of SQL for a single SELECT), but
   they can execute lightning-fast and heavily reduce the query count for
   a page: rather than querying for every object (as some ORMs do) it
   gets child objects as deep as either the developer decides, or the user
   overrides. I've often had an average query count of 1 per page _or less_
   (thanks to caching)

*  It's also fairly good at memcaching. The main use is caching the SQL
   rather than the results themselves, as the SQL can take time to
   formulate.

*  Rather than using a static code generator, it's just PHP code, so it
   can be tweaked easily.

*  It runs like a dog without APC, but runs VERY nicely with it.

*  The debug mode can be used to get high-res timings of queries and
   insert them into page source.

Quirks:

*  I wrote it in a rush, but with a mind to keep the code maintainable. My
   PHP was also still quite rusty then.

*  The split between `DatabaseObject` and `DatabaseObjectDefinition`
   wouldn't be necessary anymore, thanks to _late static bindings_ added
   in PHP 5.3.

*  When you want to do complicated queries, it's next to no help.

*  There are remnants of a weird pseudo-templating system in there. I had
   a grandiose scheme of doing a merged AJAX / PHP / PDF widget system for
   building sites and documents. It didn't work as well as the ORM did.

Regardless, I still rely on it, and I've only found one proper bug since 2009.

I'm mainly putting this on GitHub for my own benefit. Any (constructive)
comments and improvements welcome! :)
